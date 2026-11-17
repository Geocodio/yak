require "minitest/autorun"
require "tmpdir"
require_relative "../lib/fast_lanes"

class FastLanesTest < Minitest::Test
  def in_repository
    Dir.mktmpdir do |root|
      system("git -C #{root} init -q && git -C #{root} config user.email t@example.invalid && git -C #{root} config user.name t")
      File.write(File.join(root, "a.swift"), "one")
      system("git -C #{root} add . && git -C #{root} commit -qm first")
      yield root
    end
  end

  def fingerprint(root, platform: "iOS Simulator", scheme: "Yak")
    FastLanes.build_fingerprint(root, platform: platform, scheme: scheme)
  end

  def test_fingerprint_is_stable_and_follows_every_input
    in_repository do |root|
      base = fingerprint(root)
      assert_equal base, fingerprint(root)
      refute_equal base, fingerprint(root, scheme: "Other")
      refute_equal base, fingerprint(root, platform: "tvOS Simulator")
      File.write(File.join(root, "a.swift"), "two")
      edited = fingerprint(root)
      refute_equal base, edited
      File.write(File.join(root, "new.swift"), "x")
      added = fingerprint(root)
      refute_equal edited, added
      File.write(File.join(root, "new.swift"), "y")
      refute_equal added, fingerprint(root)
      File.write(File.join(root, "Config.xcconfig"), "A = 1")
      refute_equal added, fingerprint(root)
    end
  end

  def test_marker_round_trip
    Dir.mktmpdir do |directory|
      marker = File.join(directory, "sub", "marker")
      refute FastLanes.unchanged?(marker, "abc")
      FastLanes.remember(marker, "abc")
      assert FastLanes.unchanged?(marker, "abc")
      refute FastLanes.unchanged?(marker, "abd")
    end
  end

  def test_tree_fingerprint_follows_content
    Dir.mktmpdir do |directory|
      File.write(File.join(directory, "a.sh"), "1")
      before = FastLanes.tree_fingerprint([directory])
      File.write(File.join(directory, "a.sh"), "2")
      refute_equal before, FastLanes.tree_fingerprint([directory])
    end
  end

  def test_slots_are_limited_released_and_stale_ones_taken
    Dir.mktmpdir do |directory|
      waits = []
      thread = nil
      FastLanes.with_slot(count: 1, directory: directory) do
        assert File.directory?(File.join(directory, "slot-1"))
        thread = Thread.new do
          FastLanes.with_slot(count: 1, directory: directory, poll_seconds: 0.05, on_wait: ->(count) { waits << count }) { :ran }
        end
        sleep 0.3
        assert thread.alive?
        assert_equal [1], waits
      end
      assert_equal :ran, thread.value
      refute File.exist?(File.join(directory, "slot-1"))

      # A dead pid is stale and is taken over.
      dead = Process.spawn("true")
      Process.wait(dead)
      Dir.mkdir(File.join(directory, "slot-1"))
      File.write(File.join(directory, "slot-1", "pid"), dead.to_s)
      assert_equal :ok, FastLanes.with_slot(count: 1, directory: directory) { :ok }
    end
  end

  def test_slot_is_released_when_the_block_raises
    Dir.mktmpdir do |directory|
      assert_raises(RuntimeError) { FastLanes.with_slot(count: 2, directory: directory) { raise "boom" } }
      assert_empty Dir.children(directory)
    end
  end
end
