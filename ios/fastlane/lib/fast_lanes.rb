require "digest"
require "fileutils"
require "open3"

# Helpers that keep the local test loop fast: a fingerprint that says whether a build is still current, and a
# machine-wide limit on simulator test jobs. Nothing here runs on CI.
module FastLanes
  # Digest of everything under `root` (a folder in a git checkout) that can change a build: the commit, the status,
  # the diff against the commit and the contents of untracked files, plus the platform, the scheme and the
  # ignored Config.xcconfig.
  def self.build_fingerprint(root, platform:, scheme:)
    digest = Digest::SHA256.new
    [%w[rev-parse HEAD], %w[status --porcelain -- .], %w[diff HEAD -- .]].each do |arguments|
      text, = Open3.capture2("git", "-C", root, *arguments)
      digest << text
    end
    untracked, = Open3.capture2("git", "-C", root, "ls-files", "-o", "--exclude-standard", "-z", "--", ".")
    untracked.split("\0").each { |path| digest << file_digest(File.join(root, path)) }
    digest << file_digest(File.join(root, "Config.xcconfig"))
    digest << platform << scheme
    digest.hexdigest
  end

  # Digest of the files at `paths` (files or folders), by relative name and content.
  def self.tree_fingerprint(paths)
    digest = Digest::SHA256.new
    paths.flat_map { |path| File.directory?(path) ? Dir.glob(File.join(path, "**", "*")) : [path] }
      .select { |path| File.file?(path) }.sort.each { |path| digest << path << file_digest(path) }
    digest.hexdigest
  end

  def self.file_digest(path)
    File.file?(path) ? Digest::SHA256.file(path).hexdigest : "missing"
  end

  def self.unchanged?(marker_path, fingerprint)
    File.file?(marker_path) && File.read(marker_path).strip == fingerprint
  end

  def self.remember(marker_path, fingerprint)
    FileUtils.mkdir_p(File.dirname(marker_path))
    File.write(marker_path, "#{fingerprint}\n")
  end

  SLOT_DIRECTORY = File.join("/tmp", "yak-ios-sim-slots")

  # Runs the block while holding one of `count` slot directories (`slot-<n>` holding the pid). A slot whose pid is
  # dead is stale and is taken over. `on_wait` is called with the number of slots in use, at most once a minute.
  # ponytail: a stale slot taken by two waiters at the same instant can be double-booked; a lock around the takeover
  # if that ever shows.
  def self.with_slot(count:, directory: SLOT_DIRECTORY, poll_seconds: 1, notice_seconds: 60, on_wait: nil)
    FileUtils.mkdir_p(directory)
    slot = nil
    last_notice = nil
    until slot
      slot = take_slot(count, directory)
      break if slot
      now = Time.now
      if last_notice.nil? || now - last_notice >= notice_seconds
        on_wait&.call(count)
        last_notice = now
      end
      sleep poll_seconds
    end
    yield
  ensure
    FileUtils.rm_rf(slot) if slot && File.file?(File.join(slot, "pid")) && File.read(File.join(slot, "pid")).to_i == Process.pid
  end

  def self.take_slot(count, directory)
    (1..count).each do |number|
      slot = File.join(directory, "slot-#{number}")
      FileUtils.rm_rf(slot) if stale?(slot)
      begin
        Dir.mkdir(slot)
      rescue Errno::EEXIST
        next
      end
      File.write(File.join(slot, "pid"), Process.pid.to_s)
      return slot
    end
    nil
  end

  # A slot is stale when its pid is gone. A slot without a pid file is a taker in the middle of writing it, so it
  # counts as stale only when it is older than ten seconds.
  def self.stale?(slot)
    return false unless File.directory?(slot)
    pid_path = File.join(slot, "pid")
    return Time.now - File.mtime(slot) > 10 unless File.file?(pid_path)
    Process.kill(0, File.read(pid_path).to_i)
    false
  rescue Errno::ESRCH
    true
  rescue Errno::EPERM
    false
  end
end
