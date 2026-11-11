<?php

use App\Support\SecretFilePatterns;

dataset('secret paths', [
    '.env', '.env.local', 'prod.env', 'config/.env.production', 'app.env.bak', 'a.pem', 'a.key', 'a.p12', 'a.pfx',
    'a.jks', 'a.keystore', 'a.pkcs8', 'a.p8', 'a.ppk', 'a.asc', 'a.gpg', 'id_rsa', 'id_rsa.old', 'id_dsa',
    'id_ecdsa', 'id_ed25519', 'credentials.json', 'credentials-prod.json', 'auth.json', '.npmrc', '.pypirc',
    '.netrc', '.git-credentials', '.htpasswd', '.dockercfg', '.pgpass', '.my.cnf', 'service-account-1.json',
    'client_secret_123.json', 'x.tfvars', 'terraform.tfstate', 'terraform.tfstate.backup', 'a.kdbx', 'kubeconfig',
    'secrets.yml', 'secrets.yaml', 'secrets.json', 'secrets.prod.yml', 'vault-password-file', '.vault_pass',
    '.vault_pass.txt', 'home/.aws/credentials', '.ssh/config', '.gnupg/pubring.kbx', 'a.bak', 'a.bak-2026',
    'a.orig', 'a.sqlite', 'a.sql.gz', 'my_token', 'db-password.txt', 'apikey.json', 'api_key.yml', 'api-key.env',
    'secret.csv', 'credential.bak', 'ID_RSA', 'src/../.ENV', '/home/me/.ssh/id_ed25519',
]);

dataset('harmless paths', [
    'CredentialsPolicy.php', 'TokenBucket.ts', 'password.php', '.env.example', 'credentials.json.example',
    'secrets.sample.yml', '.env.template', '.env.dist', 'token.d.ts', 'secrets.md', 'a.mdx', 'id_rsa.pub',
    'secret.crt', 'secret.cer', 'secrets/a.php', 'src/a.php', '', 'aws/credentials.php',
]);

it('flags secret-looking paths', function (string $path) {
    expect(SecretFilePatterns::matches($path))->toBeTrue();
})->with('secret paths');

it('leaves harmless paths alone', function (string $path) {
    expect(SecretFilePatterns::matches($path))->toBeFalse();
})->with('harmless paths');

it('checks a collapsed directory by its name and its files', function () {
    expect(SecretFilePatterns::matchesCollapsedDirectory('.ssh'))->toBeTrue()
        ->and(SecretFilePatterns::matchesCollapsedDirectory('.gnupg/'))->toBeTrue()
        ->and(SecretFilePatterns::matchesCollapsedDirectory('storage/keys', ['storage/keys/prod.pem']))->toBeTrue()
        ->and(SecretFilePatterns::matchesCollapsedDirectory('src', ['src/a.php']))->toBeFalse();
});

it('filters to the matching paths in input order without duplicates', function () {
    expect(SecretFilePatterns::filter(['b.php', '.env', 'a.pem', '.env']))->toBe(['.env', 'a.pem']);
});
