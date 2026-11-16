#!/usr/bin/env ruby
# Project-file checks for scripts/rules.d/release.sh. Run from the ios folder.
#   release-project.rb extension-targets   prints every target with type app-extension, one per line
#   release-project.rb versions            prints version keys set under a target
#   release-project.rb minimum             prints deployment targets above options.deploymentTarget.iOS
#   release-project.rb workflow <path>     prints what is wrong with the release workflow
#   release-project.rb nightly <path>      prints what is wrong with the nightly workflow
#   release-project.rb pipefail <path>     prints run blocks that pipe without pipefail
#   release-project.rb dependabot <path>   prints what is missing from the Dependabot configuration
#   release-project.rb pins                prints where Packages/Pins differs from the project files
# Prints one finding per line and exits 0; an unreadable file is a finding.
require "yaml"

def load_yaml(path)
  YAML.safe_load(File.read(path), aliases: true) || {}
rescue StandardError => error
  # extension-targets prints target names on stdout, so its finding goes to stderr; the other modes fail on it.
  (ARGV[0] == "extension-targets" ? $stderr : $stdout).puts "#{path}: cannot be read as YAML (#{error.class})"
  {}
end

def project_files
  ["project.yml"] + Dir.glob("*/target.yml").sort
end

def target_definitions(path)
  document = load_yaml(path)
  targets = document["targets"]
  targets.is_a?(Hash) ? targets.select { |_name, definition| definition.is_a?(Hash) } : {}
end

def secret_reference?(node)
  node.to_s.match?(/\bsecrets\./)
end

def walk(node, &block)
  case node
  when Hash then node.each { |key, value| block.call(key, value); walk(value, &block) }
  when Array then node.each { |item| walk(item, &block) }
  end
end

case ARGV[0]
when "extension-targets"
  project_files.select { |path| File.exist?(path) }.each do |path|
    target_definitions(path).each { |name, definition| puts name if definition["type"] == "app-extension" }
  end
when "versions"
  project_files.select { |path| File.exist?(path) }.each do |path|
    target_definitions(path).each do |name, definition|
      walk(definition) do |key, _value|
        puts "#{path}: target #{name} sets #{key}; the version comes from ios/VERSION and the build" if %w[MARKETING_VERSION CURRENT_PROJECT_VERSION].include?(key)
      end
    end
  end
when "minimum"
  limit = load_yaml("project.yml").dig("options", "deploymentTarget", "iOS").to_s
  if limit.empty?
    puts "project.yml: options.deploymentTarget.iOS is not set"
    exit 0
  end
  above = lambda { |value| Gem::Version.correct?(value.to_s) && Gem::Version.new(value.to_s) > Gem::Version.new(limit) }
  project_files.select { |path| File.exist?(path) }.each do |path|
    walk(load_yaml(path)) do |key, value|
      if key == "IPHONEOS_DEPLOYMENT_TARGET" && above.call(value)
        puts "#{path}: IPHONEOS_DEPLOYMENT_TARGET #{value} is above #{limit}"
      elsif key == "deploymentTarget"
        candidate = value.is_a?(Hash) ? value["iOS"] : value
        puts "#{path}: deploymentTarget #{candidate} is above #{limit}" if above.call(candidate)
      end
    end
  end
  Dir.glob("Packages/*/Package.swift").sort.each do |path|
    File.read(path).scan(/\.iOS\(\s*(?:"([\d.]+)"|\.v(\d+)(?:_(\d+))?)\s*\)/) do |quoted, major, minor|
      version = quoted || [major, minor || "0"].join(".")
      puts "#{path}: .iOS(#{version}) is above #{limit}" if above.call(version)
    end
  end
when "workflow"
  path = ARGV[1]
  text = File.read(path)
  document = load_yaml(path)
  triggers = document["on"] || document[true] || {}
  names = triggers.is_a?(Hash) ? triggers.keys.map(&:to_s) : Array(triggers).map(&:to_s)
  %w[pull_request pull_request_target].each do |name|
    puts "#{path}: has a #{name} trigger; the release workflow must never run pull request code" if names.include?(name)
  end
  branches = triggers.is_a?(Hash) && triggers["push"].is_a?(Hash) ? triggers["push"]["branches"] : nil
  puts "#{path}: push branches must be exactly [main]" if names.include?("push") && branches != ["main"]
  permissions = document["permissions"]
  puts "#{path}: the top-level permissions must be exactly contents: read" unless permissions.is_a?(Hash) && permissions == { "contents" => "read" }
  puts "#{path}: the top-level env must not hold a secret; pass secrets to the one step that needs them" if secret_reference?(document["env"])
  (document["jobs"] || {}).each do |name, job|
    next unless job.is_a?(Hash)
    puts "#{path}: job #{name} env must not hold a secret; pass secrets to the one step that needs them" if secret_reference?(job["env"])
    steps = job["steps"].is_a?(Array) ? job["steps"] : []
    verify = steps.index { |step| step.is_a?(Hash) && step["run"].to_s.include?("verify-release-tag.sh") }
    first_secret = steps.index { |step| step.is_a?(Hash) && secret_reference?(step) }
    puts "#{path}: job #{name} must run scripts/verify-release-tag.sh before any step that reads a secret" if first_secret && (verify.nil? || verify > first_secret)
    puts "#{path}: job #{name} must run only from refs/heads/main: add `github.ref == 'refs/heads/main'` to its if" unless job["if"].to_s.match?(/github\.ref\s*==\s*'refs\/heads\/main'/)
  end
  walk(document) do |key, value|
    next unless key == "uses" && value.is_a?(String)
    puts "#{path}: #{value} is not pinned to a commit and is not from actions/ or this repository" unless value.start_with?("actions/", "./") || value.match?(/@[0-9a-f]{40}\z/)
  end
  puts "#{path}: environment: testflight is missing" unless text.match?(/^\s*environment:\s*testflight\s*$/)
  puts "#{path}: cancel-in-progress must be false" unless text.match?(/cancel-in-progress:\s*false\b/)
  (document["jobs"] || {}).each do |name, job|
    condition = job.is_a?(Hash) ? job["if"].to_s : ""
    puts "#{path}: job #{name} needs `if: vars.YAK_IOS_RELEASE == 'on'` so a fork skips quietly" unless condition.include?("vars.YAK_IOS_RELEASE == 'on'")
    condition.scan(/\bvars\.([A-Za-z0-9_]+)/).flatten.each do |variable|
      puts "#{path}: job #{name} reads vars.#{variable} in its if; only vars.YAK_IOS_RELEASE is visible there" unless variable == "YAK_IOS_RELEASE"
    end
  end
when "nightly"
  path = ARGV[1]
  document = load_yaml(path)
  triggers = document["on"] || document[true] || {}
  names = triggers.is_a?(Hash) ? triggers.keys.map(&:to_s) : Array(triggers).map(&:to_s)
  names.grep(/\Apull_request/).each { |name| puts "#{path}: has a #{name} trigger; the nightly workflow runs on a schedule only" }
  puts "#{path}: schedule and workflow_dispatch triggers are required" unless (%w[schedule workflow_dispatch] - names).empty?
  puts "#{path}: must not reference secrets" if File.read(path).match?(/\bsecrets\./)
  (document["jobs"] || {}).each do |name, job|
    puts "#{path}: job #{name} needs timeout-minutes of at most 40" unless job.is_a?(Hash) && job["timeout-minutes"].to_i.between?(1, 40)
  end
when "pipefail"
  path = ARGV[1]
  document = load_yaml(path)
  default_shell = document.dig("defaults", "run", "shell").to_s
  (document["jobs"] || {}).each do |name, job|
    next unless job.is_a?(Hash)
    job_shell = job.dig("defaults", "run", "shell").to_s
    (job["steps"].is_a?(Array) ? job["steps"] : []).each do |step|
      next unless step.is_a?(Hash) && step["run"].is_a?(String)
      # A pipe is a lone `|`; `||` and a `|` inside quotes are rare enough to flag and fix by hand.
      next unless step["run"].match?(/(?<!\|)\|(?!\|)/)
      shell = (step["shell"] || (job_shell.empty? ? default_shell : job_shell)).to_s
      next if shell.start_with?("bash") && !shell.include?("{0}") || step["run"].match?(/^\s*set\s+-[a-z]*o\s+pipefail|^\s*set\s+-o\s+pipefail/)
      puts "#{path}: job #{name}, step #{step["name"] || "(unnamed)"} pipes without pipefail; set `shell: bash` or start the block with `set -o pipefail`"
    end
  end
when "dependabot"
  path = ARGV[1]
  unless File.exist?(path)
    puts "#{path}: is missing"
    exit 0
  end
  updates = load_yaml(path)["updates"]
  updates = [] unless updates.is_a?(Array)
  { "github-actions" => "/", "bundler" => "/ios", "swift" => "/ios/Packages/Pins" }.each do |ecosystem, directory|
    found = updates.any? { |entry| entry.is_a?(Hash) && entry["package-ecosystem"] == ecosystem && entry["directory"] == directory }
    puts "#{path}: no #{ecosystem} update for directory #{directory}" unless found
  end
when "pins"
  normalize = lambda { |url| url.to_s.strip.downcase.sub(%r{/+\z}, "").sub(/\.git\z/, "") }
  manifest = "Packages/Pins/Package.swift"
  unless File.exist?(manifest)
    puts "#{manifest}: is missing"
    exit 0
  end
  source = File.read(manifest).lines.reject { |line| line.strip.start_with?("//") }.join
  pinned = {}
  source.scan(/\.package\(\s*url:\s*"([^"]+)"\s*,\s*([^)]*)\)/m) do |url, rest|
    version = rest[/\Aexact:\s*"([^"]+)"/, 1]
    puts "#{manifest}: #{url} must use exact: with a version string" unless version
    pinned[normalize.call(url)] = [url, version]
  end
  puts "Packages/Pins/Package.resolved: is missing; run swift package resolve in Packages/Pins and commit it" if !pinned.empty? && !File.exist?("Packages/Pins/Package.resolved")
  used = {}
  (["project.yml"] + Dir.glob("project-*.yml").sort + Dir.glob("*/target.yml").sort).select { |file| File.exist?(file) }.each do |file|
    packages = load_yaml(file)["packages"]
    next unless packages.is_a?(Hash)
    packages.each_value do |package|
      next unless package.is_a?(Hash) && package["url"]
      used[normalize.call(package["url"])] = [package["url"], (package["exactVersion"] || package["version"]).to_s, file]
    end
  end
  used.each do |key, (url, version, file)|
    if !pinned.key?(key)
      puts "#{file}: #{url} is not pinned in #{manifest}; Dependabot cannot see it"
    elsif pinned[key][1] != version
      puts "#{manifest}: #{url} is #{pinned[key][1]} but #{file} pins #{version}; change both in the same pull request"
    end
  end
  pinned.each { |key, (url, _)| puts "#{manifest}: #{url} is used by no project file" unless used.key?(key) }
else
  warn "usage: release-project.rb extension-targets|versions|minimum|workflow <path>|nightly <path>|pipefail <path>|dependabot <path>|pins"
  exit 2
end
