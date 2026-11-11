package main

import (
	"bytes"
	"fmt"
	"log/slog"
	"strings"
	"testing"
)

func TestCredentialNeverPrintsItsSecret(t *testing.T) {
	credential := Credential{Kind: "api_key", Secret: "sk-ant-SECRET", AccountID: 3}

	outputs := []string{
		fmt.Sprintf("%v", credential),
		fmt.Sprintf("%+v", credential),
		fmt.Sprintf("%#v", credential),
		fmt.Sprintf("%s", credential),
		fmt.Sprintf("%v", &credential),
	}
	var text, structured bytes.Buffer
	slog.New(slog.NewTextHandler(&text, nil)).Info("lookup", "credential", credential)
	slog.New(slog.NewJSONHandler(&structured, nil)).Info("lookup", "credential", credential)
	outputs = append(outputs, text.String(), structured.String())

	for _, output := range outputs {
		if strings.Contains(output, "SECRET") {
			t.Errorf("output leaks the secret: %s", output)
		}
	}
}
