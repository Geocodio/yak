#!/usr/bin/env perl
# Swift source scans for scripts/rules.d/release.sh. Usage:
#   release-scan.pl logging|traps|hosts <file>...
#   release-scan.pl catalog <catalog.xcstrings,catalog.xcstrings> <file>...
#   release-scan.pl tokens <regex> <file>...
# Prints one "path:line: message" per finding (or one token per line for `tokens`) and exits 0.
# Comments are blanked out first; string literals are walked with their \( ) interpolations, so a call
# split over several lines and a parenthesis inside a string are read correctly.
use strict;
use warnings;
use JSON::PP;

my ($mode, @arguments) = @ARGV;
die "usage: release-scan.pl <mode> ...\n" unless defined $mode;

sub skip_string {
    my ($t, $i, $interpolations) = @_;
    my $n = length $t;
    my $isTriple = substr($t, $i, 3) eq '"""';
    $i += $isTriple ? 3 : 1;
    while ($i < $n) {
        my $c = substr($t, $i, 1);
        if ($c eq '\\') {
            if (substr($t, $i + 1, 1) eq '(') {
                my $end = skip_parens($t, $i + 1, $interpolations);
                push @$interpolations, [$i + 2, $end - 1] if $interpolations;
                $i = $end;
                next;
            }
            $i += 2;
            next;
        }
        return $i + ($isTriple ? 3 : 1) if $isTriple ? substr($t, $i, 3) eq '"""' : $c eq '"';
        return $i if !$isTriple && $c eq "\n";
        $i++;
    }
    return $n;
}

# $i is the index of "("; returns the index after the matching ")".
sub skip_parens {
    my ($t, $i, $interpolations) = @_;
    my ($n, $depth) = (length $t, 0);
    while ($i < $n) {
        my $c = substr($t, $i, 1);
        if ($c eq '"') { $i = skip_string($t, $i, $interpolations); next; }
        if ($c eq '(') { $depth++; }
        elsif ($c eq ')') { $depth--; return $i + 1 if $depth == 0; }
        $i++;
    }
    return $n;
}

sub mask_comments {
    my ($t) = @_;
    my ($o, $i, $n) = ($t, 0, length $t);
    while ($i < $n) {
        my $c = substr($t, $i, 1);
        if ($c eq '"') { $i = skip_string($t, $i, undef); next; }
        my $two = substr($t, $i, 2);
        if ($two eq '//') {
            my $e = index($t, "\n", $i);
            $e = $n if $e < 0;
            substr($o, $i, $e - $i) = ' ' x ($e - $i);
            $i = $e;
            next;
        }
        if ($two eq '/*') {
            my ($depth, $j) = (0, $i);
            while ($j < $n) {
                my $pair = substr($t, $j, 2);
                if ($pair eq '/*') { $depth++; $j += 2; next; }
                if ($pair eq '*/') { $depth--; $j += 2; last if $depth == 0; next; }
                $j++;
            }
            my $chunk = substr($o, $i, $j - $i);
            $chunk =~ s/[^\n]/ /g;
            substr($o, $i, $j - $i) = $chunk;
            $i = $j;
            next;
        }
        $i++;
    }
    return $o;
}

# Top-level arguments between the parentheses that open at $open and end before $end.
sub split_arguments {
    my ($t, $open, $end) = @_;
    my (@parts, $depth);
    my ($start, $i) = ($open + 1, $open + 1);
    $depth = 0;
    while ($i < $end - 1) {
        my $c = substr($t, $i, 1);
        if ($c eq '"') { $i = skip_string($t, $i, undef); next; }
        if ($c =~ /[(\[{]/) { $depth++; }
        elsif ($c =~ /[)\]}]/) { $depth--; }
        elsif ($c eq ',' && $depth == 0) { push @parts, substr($t, $start, $i - $start); $start = $i + 1; }
        $i++;
    }
    push @parts, substr($t, $start, $end - 1 - $start);
    return map { my $p = $_; $p =~ s/^\s+|\s+$//g; $p } @parts;
}

sub line_of {
    my ($t, $pos) = @_;
    return 1 + (substr($t, 0, $pos) =~ tr/\n//);
}

sub slurp {
    my ($path) = @_;
    open(my $handle, '<:encoding(UTF-8)', $path) or return undef;
    local $/;
    my $text = <$handle>;
    close $handle;
    return $text;
}

# ---- logging -------------------------------------------------------------------------------------

my %sensitiveParts = map { $_ => 1 } qw(token authorization bearer header body text title content answer brief
    password secret cookie ticket url path payload);
my %metadataParts = map { $_ => 1 } qw(type kind count length id);
my $levels = 'debug|info|notice|error|fault|warning|trace|critical|log';

sub name_parts {
    my ($identifier) = @_;
    my @parts;
    for my $chunk (split /_+/, $identifier) {
        push @parts, map { lc } ($chunk =~ /[A-Z]?[a-z0-9]+|[A-Z]+(?![a-z])/g);
    }
    return @parts;
}

# Returns why an interpolation must not be logged, or undef.
sub private_interpolation {
    my ($expression) = @_;
    my $trimmed = $expression;
    $trimmed =~ s/,\s*privacy:.*$//s;
    $trimmed =~ s/^\s+|\s+$//g;
    return 'the error itself (use type(of: error))'
        if $trimmed =~ /^(?:error|error\.localizedDescription|String\(describing:\s*error\))$/;
    (my $code = $expression) =~ s/"(?:[^"\\]|\\.)*"/""/g;
    while ($code =~ /([A-Za-z_]\w*(?:\s*\.\s*[A-Za-z_]\w*)*)/g) {
        my @chain = split /\s*\.\s*/, $1;
        my @lastParts = name_parts($chain[-1]);
        next if @lastParts && $metadataParts{$lastParts[-1]};
        for my $identifier (@chain) {
            for my $part (name_parts($identifier)) {
                return "'$identifier', which can hold private text" if $sensitiveParts{$part};
            }
        }
    }
    return undef;
}

sub scan_logging {
    my ($path, $t) = @_;
    my @openings;
    while ($t =~ /(?:^|(?<=[\s.({=,]))(?:logFailure|log)\s*\(/mg) { push @openings, $+[0] - 1; }
    while ($t =~ /(?<![A-Za-z0-9_])os_log\s*\(/g) { push @openings, $+[0] - 1; }
    while ($t =~ /(?<![A-Za-z0-9_])logger\s*\.\s*(?:$levels)\s*\(/g) { push @openings, $+[0] - 1; }
    while ($t =~ /(?<![A-Za-z0-9_])Logger\s*\(/g) {
        my $after = skip_parens($t, $+[0] - 1, undef);
        if (substr($t, $after) =~ /\A\s*\.\s*(?:$levels)\s*\(/) { push @openings, $after + $+[0] - 1; }
    }
    my %seen;
    for my $open (sort { $a <=> $b } @openings) {
        my @interpolations;
        skip_parens($t, $open, \@interpolations);
        for my $span (@interpolations) {
            my $expression = substr($t, $span->[0], $span->[1] - $span->[0]);
            my $why = private_interpolation($expression) or next;
            my $line = line_of($t, $span->[0]);
            next if $seen{"$line:$expression"}++;
            print "$path:$line: a log call interpolates $why\n";
        }
    }
}

# ---- trap messages -------------------------------------------------------------------------------

sub is_plain_literal {
    my ($argument) = @_;
    $argument =~ s/^\w+\s*:\s*(?=")//;
    return 1 if $argument =~ /\A"""(?:(?!""").)*"""\z/s && $argument !~ /\\\(/;
    return $argument =~ /\A"(?:[^"\\\n]|\\[^(])*"\z/ ? 1 : 0;
}

sub scan_traps {
    my ($path, $t) = @_;
    my %messageIndex = (fatalError => 0, preconditionFailure => 0, assertionFailure => 0, precondition => 1, assert => 1);
    while ($t =~ /(?<![A-Za-z0-9_])(fatalError|preconditionFailure|assertionFailure|precondition|assert)\s*\(/g) {
        my ($name, $open) = ($1, $+[0] - 1);
        my $end = skip_parens($t, $open, undef);
        my @arguments = split_arguments($t, $open, $end);
        my $message = $arguments[$messageIndex{$name}];
        next if !defined $message || $message eq '';
        next if is_plain_literal($message);
        print "$path:" . line_of($t, $open) . ": $name has a message that is not a plain string literal\n";
    }
}

# ---- hosts ---------------------------------------------------------------------------------------

sub scan_hosts {
    my ($path, $t) = @_;
    while ($t =~ /"[^"\n]*:\/\/[A-Za-z0-9][^"\n]*"/g) {
        print "$path:" . line_of($t, $-[0]) . ": a string literal names a host\n";
    }
}

# ---- catalog keys --------------------------------------------------------------------------------

sub normalize_key {
    my ($key) = @_;
    $key =~ s/%(?:\d+\$)?(?:@|l{0,2}[dui]|lf|f|s|c)/\0/g;
    return $key;
}

sub normalize_literal {
    my ($literal) = @_;
    my %escapes = (n => "\n", t => "\t", '"' => '"', '\\' => '\\');
    my ($out, $i, $n) = ('', 1, length($literal) - 1);
    while ($i < $n) {
        my $c = substr($literal, $i, 1);
        if ($c eq '\\') {
            my $next = substr($literal, $i + 1, 1);
            if ($next eq '(') { $i = skip_parens($literal, $i + 1, undef); $out .= "\0"; next; }
            $out .= exists $escapes{$next} ? $escapes{$next} : $next;
            $i += 2;
            next;
        }
        $out .= $c;
        $i++;
    }
    return $out;
}

my %catalogKeys;
if ($mode eq 'catalog') {
    my $list = shift @arguments;
    for my $catalog (grep { length } split /,/, $list // '') {
        my $text = slurp($catalog);
        my $data = defined $text ? eval { decode_json(do { utf8::encode($text); $text }) } : undef;
        die "release-scan: cannot read catalog $catalog\n" unless $data && ref $data->{strings} eq 'HASH';
        $catalogKeys{normalize_key($_)} = 1 for keys %{ $data->{strings} };
    }
}

sub scan_catalog {
    my ($path, $t) = @_;
    my $callers = qr/(?<![A-Za-z0-9_])(?:Text|Button|Label|Toggle|Section|LocalizedStringResource)\s*\(
        |\.\s*(?:navigationTitle|accessibilityLabel|accessibilityHint|help)\s*\(
        |(?<![A-Za-z0-9_])String\s*\(\s*localized\s*:/x;
    while ($t =~ /$callers/g) {
        my $matched = substr($t, $-[0], $+[0] - $-[0]);
        my ($open, $first);
        if ($matched =~ /localized\s*:$/) {
            my $paren = index($matched, '(');
            $open = $-[0] + $paren;
            $first = substr($t, $+[0]);
            $first =~ s/\A\s+//;
        } else {
            $open = $+[0] - 1;
            $first = undef;
        }
        my $end = skip_parens($t, $open, undef);
        my @arguments = split_arguments($t, $open, $end);
        my $argument = defined $first ? (split_arguments($t, $open, $end))[0] : $arguments[0];
        next unless defined $argument;
        $argument =~ s/^localized\s*:\s*//;
        next unless $argument =~ /\A"/ && $argument !~ /\A"""/;
        next unless skip_string($argument, 0, undef) == length $argument;
        my $key = normalize_literal($argument);
        next if $key eq '' || $catalogKeys{$key};
        (my $shown = $key) =~ s/\0/\\(...)/g;
        print "$path:" . line_of($t, $open) . ": \"$shown\" is not a key in the string catalogs\n";
    }
}

# ---- tokens --------------------------------------------------------------------------------------

my $tokenPattern;
if ($mode eq 'tokens') {
    $tokenPattern = shift @arguments;
}

my %found;
for my $path (@arguments) {
    my $text = slurp($path);
    next unless defined $text;
    my $t = mask_comments($text);
    if ($mode eq 'logging') { scan_logging($path, $t); }
    elsif ($mode eq 'traps') { scan_traps($path, $t); }
    elsif ($mode eq 'hosts') { scan_hosts($path, $t); }
    elsif ($mode eq 'catalog') { scan_catalog($path, $t); }
    elsif ($mode eq 'tokens') { $found{$1} = 1 while $t =~ /(?<![A-Za-z0-9_])($tokenPattern)(?![A-Za-z0-9_])/g; }
    else { die "release-scan: unknown mode $mode\n"; }
}
print "$_\n" for sort keys %found;
