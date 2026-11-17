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

  def test_realtime_needs_a_private_channel_subscription
    subscribe = "\e[90m  1\e[39m\e[34m{\e[0m\n  \"event\": \"pusher:subscribe\",\n  \"data\": {\n    \"channel\": \"private-thread.abc\"\n"
    assert E2eLane.realtime_subscribed?(subscribe)
    refute E2eLane.realtime_subscribed?(subscribe.sub("private-", "presence-"))
    refute E2eLane.realtime_subscribed?("")
  end
end
