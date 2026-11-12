package main

// PlaceholderRevoker forgets the credential cached for a placeholder once its runner ended.
// The auth proxy implements it.
type PlaceholderRevoker interface {
	Revoke(placeholder string)
}

type noopRevoker struct{}

func (noopRevoker) Revoke(string) {}
