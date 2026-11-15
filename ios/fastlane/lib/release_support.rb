# Pure helpers for the release lanes. Nothing here talks to a network or reads the environment on its own.
module ReleaseSupport
  CONFIG_KEYS = %w[YAK_TEAM_ID YAK_BUNDLE_ID YAK_APP_GROUP YAK_DISPLAY_NAME YAK_SENTRY_DSN].freeze
  REQUIRED_CONFIG_KEYS = (CONFIG_KEYS - %w[YAK_SENTRY_DSN]).freeze
  REQUIRED_RELEASE_VARIABLES = %w[YAK_TEAM_ID YAK_BUNDLE_ID YAK_APP_GROUP YAK_DISPLAY_NAME YAK_TESTER_GROUP].freeze
  REQUIRED_RELEASE_SECRETS = %w[ASC_KEY_ID ASC_ISSUER_ID ASC_KEY_CONTENT].freeze

  MAXIMUM_NOTE_LINES = 20
  MAXIMUM_NOTE_LINE_LENGTH = 120
  MAXIMUM_NOTES_LENGTH = 4000
  MAXIMUM_TESTERS = 100
  BUILD_NUMBER_PATTERN = /\A\d+(\.\d+)*\z/
  ADDRESS_PATTERN = /\A[^@\s]+@[^@\s]+\.[^@\s]+\z/
  HIDDEN_CHARACTERS = /[\p{Cc}\p{Cf}]/

  # The message names the variable or file and the fix.
  class Refusal < StandardError; end

  def self.missing_variables(environment, names)
    names.select { |name| environment[name].to_s.strip.empty? }
  end

  # Writes Config.xcconfig text. A "//" would start an xcconfig comment, so every slash that
  # precedes another slash is followed by an empty variable reference, "/$()".
  def self.config_text(values)
    CONFIG_KEYS.map do |key|
      value = values[key].to_s
      if REQUIRED_CONFIG_KEYS.include?(key) && value.strip.empty?
        raise Refusal, "#{key} is not set. Add it to the testflight environment's variables."
      end
      if value.match?(/[[:cntrl:]]/) || value.include?("$") || value.include?("#include") || value.include?("\\")
        raise Refusal, "#{key} holds a newline, a control character, a backslash, \"$\" or \"#include\", which Config.xcconfig cannot store. Fix the value of #{key}."
      end
      "#{key} = #{value.gsub(%r{/(?=/)}, "/$()")}".rstrip + "\n"
    end.join
  end

  def self.read_config(text)
    text.each_line.each_with_object({}) do |line, result|
      stripped = line.strip
      next if stripped.empty? || stripped.start_with?("//")
      key, separator, value = stripped.partition("=")
      next if separator.empty?
      result[key.strip] = value.split("//", 2).first.to_s.strip.gsub("/$()", "/")
    end
  end

  def self.build_number(run_number:, attempt:, latest_uploaded:)
    unless latest_uploaded.nil? || latest_uploaded.match?(BUILD_NUMBER_PATTERN)
      raise Refusal, "TestFlight reported the latest build as #{latest_uploaded.inspect}, which is not a build number. Check the App Store Connect API key and that TestFlight lists a numeric build."
    end
    if run_number.nil?
      return latest_uploaded.nil? ? "1" : (latest_uploaded.split(".").first.to_i + 1).to_s
    end
    unless run_number.is_a?(Integer) && run_number.positive? && attempt.is_a?(Integer) && attempt.positive?
      raise Refusal, "The run number and attempt must be positive whole numbers. Check GITHUB_RUN_NUMBER, GITHUB_RUN_ATTEMPT and YAK_BUILD_NUMBER_OFFSET."
    end
    number = attempt == 1 ? run_number.to_s : "#{run_number}.#{attempt}"
    if latest_uploaded && Gem::Version.new(number) <= Gem::Version.new(latest_uploaded)
      raise Refusal, "Build #{number} is not above TestFlight's latest build #{latest_uploaded}. " \
                     "The workflow's run number restarted; set YAK_BUILD_NUMBER_OFFSET or upload once locally."
    end
    number
  end

  def self.scrub(value)
    value.to_s.dup.force_encoding(Encoding::UTF_8).scrub("")
  end

  # Only titles of merged pull requests (a subject ending in "(#N)") reach the notes.
  def self.release_notes(subjects, fallback:)
    lines = subjects.map { |subject| scrub(subject).gsub(HIDDEN_CHARACTERS, "").strip }
                    .select { |subject| subject.match?(/\(#\d+\)\z/) }
                    .uniq
                    .first(MAXIMUM_NOTE_LINES)
                    .map { |subject| "- #{subject[0, MAXIMUM_NOTE_LINE_LENGTH]}" }
    total = 0
    kept = lines.take_while { |line| (total += line.length + 1) - 1 <= MAXIMUM_NOTES_LENGTH }
    kept.empty? ? fallback : kept.join("\n")
  end

  def self.testers(text)
    addresses = []
    text.to_s.dup.force_encoding(Encoding::UTF_8).each_line.with_index(1) do |line, number|
      raise Refusal, "The testers file has an invalid email address on line #{number}. Fix that line." unless line.valid_encoding?
      entry = line.sub(/#.*/, "").strip
      next if entry.empty?
      if !entry.match?(ADDRESS_PATTERN) || entry.match?(HIDDEN_CHARACTERS)
        raise Refusal, "The testers file has an invalid email address on line #{number}. Fix that line."
      end
      addresses << entry.downcase
    end
    addresses.uniq!
    if addresses.size > MAXIMUM_TESTERS
      raise Refusal, "The testers file lists #{addresses.size} addresses; TestFlight allows #{MAXIMUM_TESTERS} internal testers. Remove addresses from the testers file."
    end
    addresses
  end

  def self.last_release_tag(tags_with_dates)
    tags_with_dates.max_by { |_, date| date }&.first
  end

  def self.release_tag(version:, build:)
    "ios/v#{version}-#{build}"
  end

  # Splits "ios/v<version>-<build>" into [version, build]; nil when the tag is not a release tag.
  def self.release_tag_parts(tag)
    match = tag.to_s.match(%r{\Aios/v(\d+\.\d+\.\d+)-(\d+(?:\.\d+)*)\z})
    match && [match[1], match[2]]
  end

  # "tester@example.com" becomes "t***@example.com" so a log never lists a tester address.
  def self.mask_address(address)
    local, separator, domain = address.to_s.rpartition("@")
    return "*" if separator.empty? || local.empty? || domain.empty?
    "#{local[0]}***@#{domain}"
  end

  # Groups to pass to the TestFlight upload. An internal group with access to all builds receives every
  # build on its own, and App Store Connect rejects naming it ("Cannot add internal group to a build").
  def self.upload_groups(name, internal:, all_builds:)
    internal && all_builds ? [] : [name]
  end

  # xcodebuild arguments that read the key id and issuer id from the environment, so the build log's
  # command line never holds them.
  def self.signing_arguments
    "-allowProvisioningUpdates -authenticationKeyPath \"$YAK_RELEASE_KEY_PATH\" " \
      "-authenticationKeyID \"$ASC_KEY_ID\" -authenticationKeyIssuerID \"$ASC_ISSUER_ID\""
  end

  def self.release_version(text)
    raise Refusal, "ios/VERSION must hold a version like 1.2.0." unless text.to_s.match?(/\A\d+\.\d+\.\d+\n?\z/)
    text.strip
  end

  # Rows that still need the owner's action and a new bootstrap run. "check by hand" rows are not counted.
  def self.pending_count(rows)
    rows.count { |row| row[1] == "needs a person" }
  end
end
