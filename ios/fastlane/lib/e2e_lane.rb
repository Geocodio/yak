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

  # Reverb's debug log pretty-prints each message over several lines, in colour: a "pusher:subscribe" event whose
  # channel is a private one means the app used the realtime connection.
  def self.realtime_subscribed?(reverb_log)
    text = reverb_log.to_s.gsub(/\e\[[0-9;]*m/, "")
    text.include?("pusher:subscribe") && text.match?(/"channel": "private-[^"]+"/)
  end
end
