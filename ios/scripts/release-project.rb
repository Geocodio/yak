#!/usr/bin/env ruby
# Project-file checks for scripts/rules.d/release.sh. Run from the ios folder.
#   release-project.rb extension-targets   prints every target with type app-extension, one per line
#   release-project.rb versions            prints version keys set under a target
#   release-project.rb minimum             prints deployment targets above options.deploymentTarget.iOS
#   release-project.rb workflow <path>     prints what is wrong with the release workflow
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
  puts "#{path}: environment: testflight is missing" unless text.match?(/^\s*environment:\s*testflight\s*$/)
  puts "#{path}: cancel-in-progress must be false" unless text.match?(/cancel-in-progress:\s*false\b/)
  (document["jobs"] || {}).each do |name, job|
    condition = job.is_a?(Hash) ? job["if"].to_s : ""
    condition.scan(/\bvars\.([A-Za-z0-9_]+)/).flatten.each do |variable|
      puts "#{path}: job #{name} reads vars.#{variable} in its if; only vars.YAK_IOS_RELEASE is visible there" unless variable == "YAK_IOS_RELEASE"
    end
  end
else
  warn "usage: release-project.rb extension-targets|versions|minimum|workflow <path>"
  exit 2
end
