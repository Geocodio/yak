require "minitest/autorun"
require "tmpdir"
require "json"
require "base64"
require_relative "../lib/e2e_lane"

class E2eLaneTest < Minitest::Test
  SEED = JSON.generate(
    "url" => "http://127.0.0.1:18000",
    "phones" => {"main" => {"name" => "Test phone", "token" => "token-main-aaaa"}, "second" => {"name" => "Second", "token" => "token-second-bbbb"}}
  )

  def test_selection_covers_every_journey_or_one_test
    assert_equal "-only-testing:YakUITests/JourneyTests", E2eLane.selection(nil)
    assert_equal "-only-testing:YakUITests/JourneyTests", E2eLane.selection("")
    assert_equal "-only-testing:YakUITests/JourneyTests/testJourney1Pairs", E2eLane.selection("testJourney1Pairs")
    assert_equal "-only-testing:YakUITests/JourneyTests/testJourney1Pairs", E2eLane.selection("JourneyTests/testJourney1Pairs")
  end

  def test_selection_refuses_what_is_not_one_test_name
    ["PairingSmokeTests/testX", "test X; rm -rf /", "Journey1", "testA/testB"].each do |value|
      assert_raises(ArgumentError) { E2eLane.selection(value) }
    end
  end

  def test_secrets_are_the_tokens_and_the_base64_seed
    secrets = E2eLane.secrets(SEED)
    assert_equal ["token-main-aaaa", "token-second-bbbb", Base64.strict_encode64(SEED)], secrets
    assert_raises(ArgumentError) { E2eLane.secrets(JSON.generate("phones" => {})) }
  end

  def test_scan_finds_a_token_in_text_binary_and_a_folder_and_leaves_clean_files
    Dir.mktmpdir do |root|
      File.write(File.join(root, "clean.log"), "nothing here")
      File.write(File.join(root, "leak.log"), "Authorization: Bearer token-main-aaaa\n")
      Dir.mkdir(File.join(root, "bundle"))
      File.binwrite(File.join(root, "bundle", "blob"), "\x00\xff".b + "token-second-bbbb".b + "\x01".b)
      found = E2eLane.files_with_secrets([root], E2eLane.secrets(SEED)).map { |path| File.basename(path) }.sort
      assert_equal %w[blob leak.log], found
    end
  end

  def test_scan_finds_a_secret_that_spans_two_chunks
    Dir.mktmpdir do |root|
      path = File.join(root, "big")
      File.binwrite(path, "a".b * (E2eLane::CHUNK_BYTES - 5) + "token-main-aaaa".b + "z".b * 10)
      assert_equal [path], E2eLane.files_with_secrets([path], ["token-main-aaaa"])
    end
  end

  def test_log_copy_keeps_only_what_came_after_the_offset
    Dir.mktmpdir do |root|
      source = File.join(root, "laravel.log")
      File.write(source, "private line\nnew line\n")
      destination = File.join(root, "out", "laravel.log")
      assert E2eLane.copy_log_from_offset(source, destination, "private line\n".bytesize)
      assert_equal "new line\n", File.read(destination)
      E2eLane.copy_log_from_offset(source, destination, 10_000)
      assert_equal "private line\nnew line\n", File.read(destination)
      refute E2eLane.copy_log_from_offset(File.join(root, "missing"), destination, 0)
    end
  end

  def subscribe_to(channel)
    "\e[90m  1\e[39m\e[34m{\e[0m\n  \"event\": \"pusher:subscribe\",\n  \"data\": {\n    \"auth\": \"x:y\",\n    \"channel\": \"#{channel}\"\n"
  end

  def test_realtime_needs_a_subscription_to_a_thread_the_seed_did_not_build
    seeded = %w[seeded1 seeded2]
    assert E2eLane.realtime_subscribed?(subscribe_to("private-thread.new1"), seeded)
    refute E2eLane.realtime_subscribed?(subscribe_to("private-thread.seeded1"), seeded)
    refute E2eLane.realtime_subscribed?(subscribe_to("presence-thread-presence.new1"), seeded)
    refute E2eLane.realtime_subscribed?(subscribe_to("private-thread.new1").sub("pusher:subscribe", "pusher:unsubscribe"), seeded)
    refute E2eLane.realtime_subscribed?("", seeded)
  end

  def test_seeded_thread_ids
    seed = JSON.generate("threads" => {"finished" => {"id" => "a"}, "held" => {"id" => "b"}})
    assert_equal %w[a b], E2eLane.seeded_thread_ids(seed)
  end

  def test_a_cached_config_is_refused
    Dir.mktmpdir do |root|
      E2eLane.refuse_cached_config!(root)
      FileUtils.mkdir_p(File.join(root, "bootstrap/cache"))
      File.write(File.join(root, "bootstrap/cache/config.php"), "<?php")
      assert_raises(ArgumentError) { E2eLane.refuse_cached_config!(root) }
    end
  end

  def test_a_timeout_stops_the_command_and_its_children
    started = Time.now
    assert_raises(E2eLane::TimedOut) { E2eLane.run_with_timeout("sleep 30 & sleep 30", 1) }
    assert_operator Time.now - started, :<, 10
    assert E2eLane.run_with_timeout("exit 0", 5).success?
    refute E2eLane.run_with_timeout("exit 3", 5).success?
    assert E2eLane.run_with_timeout('test "$PLANTED" = yes', 5, environment: {"PLANTED" => "yes"}).success?
  end

  def run_with_server(start_fails: false, body_fails: false, keep_server: false)
    calls = []
    start = -> { calls << :start; raise "start refused" if start_fails }
    begin
      E2eLane.with_server(start: start, stop: -> { calls << :stop }, collect: -> { calls << :collect }, keep_server: keep_server) do
        calls << :body
        raise "journey failed" if body_fails
      end
    rescue RuntimeError
      calls << :raised
    end
    calls
  end

  def test_a_failed_start_stops_nothing_and_collects_nothing
    assert_equal %i[start raised], run_with_server(start_fails: true)
  end

  def test_a_started_server_is_stopped_and_collected_even_when_the_body_fails
    assert_equal %i[start body stop collect], run_with_server
    assert_equal %i[start body stop collect raised], run_with_server(body_fails: true)
  end

  def test_keep_server_leaves_the_server_running_but_still_collects
    assert_equal %i[start body collect], run_with_server(keep_server: true)
  end

  def stage(root, files)
    staging = File.join(root, "staging")
    FileUtils.mkdir_p(File.join(staging, "logs"))
    files.each { |name, content| File.binwrite(File.join(staging, name), content) }
    [staging, File.join(root, "evidence")]
  end

  def test_clean_evidence_moves_to_the_evidence_folder_and_the_staging_folder_goes
    Dir.mktmpdir do |root|
      staging, evidence = stage(root, "logs/server.log" => "all quiet")
      assert_empty E2eLane.publish_evidence(staging, evidence, E2eLane.secrets(SEED))
      assert_equal "all quiet", File.read(File.join(evidence, "logs/server.log"))
      refute File.exist?(staging)
    end
  end

  def test_a_planted_token_refuses_the_whole_evidence_and_nothing_is_published
    Dir.mktmpdir do |root|
      staging, evidence = stage(root, "logs/server.log" => "Bearer token-main-aaaa", "logs/other.log" => "fine")
      leaked = E2eLane.publish_evidence(staging, evidence, E2eLane.secrets(SEED))
      assert_equal ["server.log"], leaked.map { |path| File.basename(path) }
      refute File.exist?(evidence)
      refute File.exist?(staging)
    end
  end

  def test_evidence_is_not_published_when_no_secret_is_known
    Dir.mktmpdir do |root|
      staging, evidence = stage(root, "logs/server.log" => "x")
      refute_empty E2eLane.publish_evidence(staging, evidence, [])
      refute File.exist?(evidence)
    end
  end

  def test_an_old_evidence_folder_never_survives_a_run
    Dir.mktmpdir do |root|
      staging, evidence = stage(root, "logs/server.log" => "Bearer token-main-aaaa")
      FileUtils.mkdir_p(evidence)
      File.write(File.join(evidence, "old.log"), "old")
      E2eLane.publish_evidence(staging, evidence, E2eLane.secrets(SEED))
      refute File.exist?(evidence)
    end
  end
end
