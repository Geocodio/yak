require "minitest/autorun"
require_relative "../lib/release_support"

class ReleaseSupportTest < Minitest::Test
  DSN = "https://public@sentry.example.invalid/1".freeze

  def values(overrides = {})
    {
      "YAK_TEAM_ID" => "TEAMID1234",
      "YAK_BUNDLE_ID" => "com.example.yak",
      "YAK_APP_GROUP" => "group.com.example.yak",
      "YAK_DISPLAY_NAME" => "Example Yak",
      "YAK_SENTRY_DSN" => DSN
    }.merge(overrides)
  end

  def test_config_round_trips
    text = ReleaseSupport.config_text(values)
    assert_includes text, "https:/$()/public"
    refute_match %r{//}, text
    assert_equal values, ReleaseSupport.read_config(text)
  end

  def test_config_round_trips_runs_of_slashes
    dsn = "https://a///b"
    text = ReleaseSupport.config_text(values("YAK_SENTRY_DSN" => dsn))
    refute_match %r{//}, text
    assert_equal dsn, ReleaseSupport.read_config(text)["YAK_SENTRY_DSN"]
  end

  def test_config_writes_an_empty_dsn
    text = ReleaseSupport.config_text(values("YAK_SENTRY_DSN" => ""))
    assert_includes text.lines.map(&:chomp), "YAK_SENTRY_DSN ="
    assert_equal "", ReleaseSupport.read_config(text)["YAK_SENTRY_DSN"]
    assert_equal "", ReleaseSupport.read_config(ReleaseSupport.config_text(values.reject { |key, _| key == "YAK_SENTRY_DSN" }))["YAK_SENTRY_DSN"]
  end

  def test_config_refuses_each
    ["a\nb", "a\r\nb", "$(OTHER)", "${OTHER}", "$OTHER", "#include \"x\"", "a\\b", "a\u0007b"].each do |bad|
      error = assert_raises(ReleaseSupport::Refusal, bad.inspect) do
        ReleaseSupport.config_text(values("YAK_DISPLAY_NAME" => bad))
      end
      assert_includes error.message, "YAK_DISPLAY_NAME"
    end
  end

  def test_config_refuses_a_missing_required_key
    ["YAK_TEAM_ID", "YAK_APP_GROUP"].each do |key|
      assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.config_text(values.reject { |name, _| name == key }) }
      error = assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.config_text(values(key => "  ")) }
      assert_includes error.message, key
    end
  end

  def test_read_config_ignores_comments_and_blank_lines
    text = "// a comment\n\nYAK_TEAM_ID = TEAMID1234\n  // indented\nnot a setting\n"
    assert_equal({ "YAK_TEAM_ID" => "TEAMID1234" }, ReleaseSupport.read_config(text))
  end

  def test_read_config_cuts_inline_comments_but_keeps_escaped_slashes
    assert_equal({ "YAK_DISPLAY_NAME" => "a" }, ReleaseSupport.read_config("YAK_DISPLAY_NAME = a // c\n"))
    url = "https://example.com/x"
    written = ReleaseSupport.config_text(values("YAK_SENTRY_DSN" => url))
    assert_equal url, ReleaseSupport.read_config(written)["YAK_SENTRY_DSN"]
  end

  def test_release_notes_survives_invalid_utf8
    notes = ReleaseSupport.release_notes(["bad \xFF byte (#3)".dup.force_encoding("UTF-8"),
                                          "bin \xFF (#4)".dup.force_encoding("BINARY")], fallback: "none")
    assert_equal "- bad  byte (#3)\n- bin  (#4)", notes
  end

  def test_testers_refuses_invalid_utf8_without_crashing
    error = assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.testers("a@example.com\nb\xFF@example.com\n".dup.force_encoding("UTF-8")) }
    assert_includes error.message, "line 2"
  end

  def test_build_number_first_attempt
    assert_equal "412", ReleaseSupport.build_number(run_number: 412, attempt: 1, latest_uploaded: "411")
  end

  def test_build_number_rerun
    assert_equal "412.2", ReleaseSupport.build_number(run_number: 412, attempt: 2, latest_uploaded: "412")
  end

  def test_build_number_local
    assert_equal "413", ReleaseSupport.build_number(run_number: nil, attempt: 1, latest_uploaded: "412.2")
  end

  def test_build_number_local_first_ever
    assert_equal "1", ReleaseSupport.build_number(run_number: nil, attempt: 1, latest_uploaded: nil)
  end

  def test_build_number_ci_first_ever
    assert_equal "5", ReleaseSupport.build_number(run_number: 5, attempt: 1, latest_uploaded: nil)
  end

  def test_build_number_refuses_a_reset
    error = assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.build_number(run_number: 3, attempt: 1, latest_uploaded: "412") }
    assert_includes error.message, "Build 3 is not above TestFlight's latest build 412."
    assert_includes error.message, "YAK_BUILD_NUMBER_OFFSET"
    assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.build_number(run_number: 412, attempt: 1, latest_uploaded: "412") }
  end

  def test_build_number_refuses_garbage_latest
    ["abc", "1..2", "-1", "1.2.x", ""].each do |bad|
      error = assert_raises(ReleaseSupport::Refusal, bad.inspect) do
        ReleaseSupport.build_number(run_number: 9, attempt: 1, latest_uploaded: bad)
      end
      assert_includes error.message, bad.inspect
    end
  end

  def test_build_number_refuses_garbage_run
    assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.build_number(run_number: 0, attempt: 1, latest_uploaded: nil) }
    assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.build_number(run_number: 4, attempt: 0, latest_uploaded: nil) }
  end

  def test_notes_keeps_only_merged_pull_requests
    subjects = ["Add thread view (#12)", "Merge branch main", "WIP", "Add thread view (#12)", "Fix crash (#13)", "Mentions #14 in text"]
    assert_equal "- Add thread view (#12)\n- Fix crash (#13)", ReleaseSupport.release_notes(subjects, fallback: "None")
  end

  def test_notes_bounds
    subjects = Array.new(500) { |index| "#{"x" * 300} (##{index + 1})" }
    notes = ReleaseSupport.release_notes(subjects, fallback: "None")
    lines = notes.lines.map(&:chomp)
    assert_operator lines.size, :<=, 20
    assert(lines.all? { |line| line.length <= 122 })
    assert_operator notes.length, :<=, 4000
  end

  def test_notes_strips_control_characters
    subject = "A\e[31mB\tC\u0007D\u0085E‮F​G (#1)"
    notes = ReleaseSupport.release_notes([subject], fallback: "None")
    ["\e", "\t", "\u0007", "\u0085", "‮", "​"].each { |bad| refute_includes notes, bad }
    assert_equal "- A[31mBCDEFG (#1)", notes
  end

  def test_notes_fallback
    assert_equal "Bug fixes.", ReleaseSupport.release_notes([], fallback: "Bug fixes.")
    assert_equal "Bug fixes.", ReleaseSupport.release_notes(["no number", "(#7"], fallback: "Bug fixes.")
    assert_equal "Bug fixes.", ReleaseSupport.release_notes(["​(#1)".sub("(#1)", "")], fallback: "Bug fixes.")
  end

  def test_testers_parses
    text = "# group\n\nTester@Example.com\n  other@example.com  # note\ntester@example.com\n"
    assert_equal ["tester@example.com", "other@example.com"], ReleaseSupport.testers(text)
  end

  def test_testers_refuses_line_3
    error = assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.testers("a@example.com\n\nnot-an-address\n") }
    assert_includes error.message, "line 3"
    refute_includes error.message, "not-an-address"
  end

  def test_testers_refuses_hostile_lines
    ["a@b", "a@@example.com", "a b@example.com", "a\u0007@example.com", "a@exa​mple.com"].each do |bad|
      assert_raises(ReleaseSupport::Refusal, bad.inspect) { ReleaseSupport.testers("#{bad}\n") }
    end
  end

  def test_testers_limit
    ok = (1..100).map { |index| "t#{index}@example.com" }.join("\n")
    assert_equal 100, ReleaseSupport.testers(ok).size
    assert_raises(ReleaseSupport::Refusal) { ReleaseSupport.testers("#{ok}\nt101@example.com") }
  end

  def test_testers_empty
    assert_equal [], ReleaseSupport.testers("")
  end

  def test_last_release_tag_newest_by_date
    tags = [["ios/v0.1.0-410", Time.at(100)], ["ios/v0.1.0-412", Time.at(300)], ["ios/v0.1.0-411", Time.at(200)]]
    assert_equal "ios/v0.1.0-412", ReleaseSupport.last_release_tag(tags)
  end

  def test_last_release_tag_none
    assert_nil ReleaseSupport.last_release_tag([])
  end

  def test_release_tag
    assert_equal "ios/v0.1.0-412", ReleaseSupport.release_tag(version: "0.1.0", build: "412")
    assert_equal "ios/v0.1.0-412.2", ReleaseSupport.release_tag(version: "0.1.0", build: "412.2")
  end

  def test_missing_variables
    environment = { "A" => "x", "B" => "", "C" => "  " }
    assert_equal %w[B C D], ReleaseSupport.missing_variables(environment, %w[A B C D])
  end

  def test_mask_address
    assert_equal "t***@example.com", ReleaseSupport.mask_address("tester@example.com")
    assert_equal "*", ReleaseSupport.mask_address("")
    assert_equal "*", ReleaseSupport.mask_address("no-at-sign")
    assert_equal "a***@example.com", ReleaseSupport.mask_address("a@example.com")
    assert_equal "*", ReleaseSupport.mask_address(nil)
  end

  def test_release_tag_parts
    assert_equal ["0.1.0", "412.2"], ReleaseSupport.release_tag_parts("ios/v0.1.0-412.2")
    assert_nil ReleaseSupport.release_tag_parts("ios/v0.1.0")
    assert_nil ReleaseSupport.release_tag_parts("v0.1.0-1\nios/v0.1.0-2")
  end
end
