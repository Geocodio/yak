package secrets

import "testing"

func TestSecretPatterns(t *testing.T) {
	positives := []string{
		".env", ".env.local", "a.env", "deploy/staging.pem", "id_ed25519", "config/credentials.dev.json",
		"auth.json", ".npmrc", "ansible/vault/secrets.yml.bak-2026", ".aws/credentials", "db.sqlite",
		"dump.sql.gz", "my_apikey.txt", "token", "home/.ssh/config", ".gnupg/pubring.kbx", "infra/prod.tfvars",
		"state.tfstate.backup", "kubeconfig", "vault-password.txt", "old.php.orig", "x.key", "my.p12",
		"notes/api-key.json", "passwd", "secrets.prod.yml", "config.bak",
	}
	for _, p := range positives {
		if hit, reason := IsSecretFile(p); !hit || reason == "" {
			t.Errorf("%q should be flagged (hit=%v reason=%q)", p, hit, reason)
		}
	}
	negatives := []string{
		"id_rsa.pub", "CredentialsPolicy.php", ".env.example", ".env.template", ".env.dist", "types.d.ts",
		"README.md", "server.crt", "notes/secret-plans.md", "app.key.sample", ".ssh/id_rsa.pub",
		"TokenBucket.go", "config/db.example.json", "app/Http/Controllers/AuthController.php", "docs/token.mdx",
		"ca.cer", "src/password_reset.rb",
	}
	for _, p := range negatives {
		if hit, reason := IsSecretFile(p); hit {
			t.Errorf("%q should not be flagged (reason=%q)", p, reason)
		}
	}
}

func TestAnyInDirChecksNamesAndFiles(t *testing.T) {
	if hit, _ := AnyInDir([]string{"src/a.go", "src/b.go"}); hit {
		t.Fatal("plain files")
	}
	if hit, _ := AnyInDir([]string{"src/a.go", "src/.env.local"}); !hit {
		t.Fatal("secret file inside")
	}
	if hit, _ := AnyInDir([]string{"secrets/"}); !hit {
		t.Fatal("collapsed directory name")
	}
	if hit, _ := AnyInDir(nil); hit {
		t.Fatal("empty")
	}
}
