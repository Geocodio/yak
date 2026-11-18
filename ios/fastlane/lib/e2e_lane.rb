require "base64"
require "fileutils"
require "json"

# Decisions of the `e2e` lane that do not need fastlane: which tests run, which values must never reach an uploaded
# file, and what the server's logs have to show. The lane itself lives in E2eFastfile.
module E2eLane
  TEST_CLASS = "JourneyTests".freeze
  # How much of a file is read at once when it is scanned for a secret.
  CHUNK_BYTES = 4 * 1024 * 1024

  # The xcodebuild selection: every journey, or one test of JourneyTests by name.
  def self.selection(only)
    return "-only-testing:YakUITests/#{TEST_CLASS}" if only.nil? || only.to_s.empty?
    name = only.to_s.sub(%r{\A#{TEST_CLASS}/}, "")
    raise ArgumentError, "only: must name one test of #{TEST_CLASS}, such as testJourney1Pairs..., not #{only.inspect}" unless name.match?(/\Atest\w+\z/)
    "-only-testing:YakUITests/#{TEST_CLASS}/#{name}"
  end

  # The values that must not reach a log, an attachment or a result bundle: each phone token and the base64 seed.
  def self.secrets(seed_json)
    seed = JSON.parse(seed_json)
    tokens = seed.fetch("phones").values.map { |phone| phone.fetch("token") }
    raise ArgumentError, "the seed holds no phone token" if tokens.empty? || tokens.any? { |token| token.to_s.empty? }
    tokens + [Base64.strict_encode64(seed_json.strip)]
  end

  # The files under `paths` (files or folders) that contain any of the secrets, as raw bytes.
  def self.files_with_secrets(paths, secrets)
    needles = secrets.map { |secret| secret.b }
    overlap = needles.map(&:bytesize).max.to_i
    paths.flat_map { |path| File.directory?(path) ? Dir.glob(File.join(path, "**", "*"), File::FNM_DOTMATCH) : [path] }
      .select { |path| File.file?(path) && !File.symlink?(path) }
      .select { |path| contains_any?(path, needles, overlap) }
  end

  def self.contains_any?(path, needles, overlap)
    carry = "".b
    File.open(path, "rb") do |file|
      while (chunk = file.read(CHUNK_BYTES))
        window = carry + chunk
        return true if needles.any? { |needle| window.include?(needle) }
        carry = window.byteslice([window.bytesize - overlap, 0].max, overlap)
      end
    end
    false
  end

  # Copies the bytes of a log written after `offset`, so a developer's own lines before the start never leave the
  # machine. A log that shrank since the offset was recorded is copied whole.
  def self.copy_log_from_offset(source, destination, offset)
    return false unless File.file?(source)
    size = File.size(source)
    offset = 0 if offset > size
    FileUtils.mkdir_p(File.dirname(destination))
    File.open(source, "rb") do |input|
      input.seek(offset)
      File.binwrite(destination, input.read.to_s)
    end
    true
  end

  # The ids of the threads the seed built; a thread any journey opens afterwards is not among them.
  def self.seeded_thread_ids(seed_json)
    JSON.parse(seed_json).fetch("threads").values.map { |thread| thread.fetch("id").to_s }
  end

  # Reverb's debug log pretty-prints each message over several lines, in colour. True when a "pusher:subscribe" event
  # names the private channel of a thread the seed did not build: the one journey 2 starts, so it streamed over the
  # realtime connection and did not fall back to polling.
  def self.realtime_subscribed?(reverb_log, seeded_thread_ids)
    text = reverb_log.to_s.gsub(/\e\[[0-9;]*m/, "")
    text.split('"event": "pusher:subscribe"').drop(1).any? do |segment|
      id = segment[0, 600][/"channel": "private-thread\.([^"]+)"/, 1]
      id && !seeded_thread_ids.include?(id)
    end
  end

  # Raises when a cached config would make the server ignore scripts/e2e/env.sh and run against the developer's database.
  def self.refuse_cached_config!(repository_root)
    path = File.join(repository_root, "bootstrap/cache/config.php")
    raise ArgumentError, "#{path} exists, so the server would ignore the e2e settings; run php artisan config:clear first" if File.exist?(path)
  end

  class TimedOut < StandardError; end

  # Runs a shell command in its own process group and stops the whole group when it outlives `seconds`. The environment
  # goes to this one child and never into the command string.
  def self.run_with_timeout(command, seconds, environment: {})
    pid = Process.spawn(environment, command, pgroup: true)
    deadline = Process.clock_gettime(Process::CLOCK_MONOTONIC) + [seconds, 0].max
    loop do
      _, status = Process.wait2(pid, Process::WNOHANG)
      return status if status
      break if Process.clock_gettime(Process::CLOCK_MONOTONIC) >= deadline
      sleep 0.2
    end
    %w[TERM KILL].each do |signal|
      begin
        Process.kill(signal, -pid)
      rescue Errno::ESRCH, Errno::EPERM
        nil
      end
      25.times do
        break if Process.wait2(pid, Process::WNOHANG)
        sleep 0.2
      end
      break unless Process.waitpid(pid, Process::WNOHANG).nil?
    rescue Errno::ECHILD
      break
    end
    raise TimedOut, "stopped after #{seconds.round} s"
  end

  # Starts the server, then yields; stops the server afterwards unless `keep_server`, and always collects. A start that
  # fails stops nothing and collects nothing, because it may have been refused by a server another run started.
  def self.with_server(start:, stop:, collect:, keep_server: false)
    start.call
    begin
      yield
    ensure
      begin
        stop.call unless keep_server
      ensure
        collect.call
      end
    end
  end

  # Moves the staged evidence to `evidence` only when no file holds a secret. A leak (or no known secret to scan for)
  # deletes the staged files and publishes nothing. Returns the files that held a secret.
  def self.publish_evidence(staging, evidence, secrets)
    FileUtils.rm_rf(evidence)
    return [] unless File.directory?(staging)
    leaked = secrets.empty? ? [staging] : files_with_secrets([staging], secrets)
    if leaked.empty?
      File.rename(staging, evidence)
    else
      FileUtils.rm_rf(staging)
    end
    leaked
  end
end
