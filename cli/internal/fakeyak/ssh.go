package fakeyak

// SSHBody is the 200 body of GET /threads/{id}/ssh.
type SSHBody map[string]any

type sshError struct {
	status  int
	code    string
	details map[string]any
}

type sshState struct {
	bodies map[string]SSHBody
	errors map[string]*sshError
}

// SetSSH makes the ssh lookup for one thread answer 200 with body.
func (s *Server) SetSSH(threadID string, body SSHBody) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.ssh.bodies == nil {
		s.ssh.bodies = map[string]SSHBody{}
	}
	s.ssh.bodies[threadID] = body
	delete(s.ssh.errors, threadID)
}

// SetSSHError makes the ssh lookup for one thread answer with the given status and error code.
func (s *Server) SetSSHError(threadID string, status int, code string, details map[string]any) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.ssh.errors == nil {
		s.ssh.errors = map[string]*sshError{}
	}
	s.ssh.errors[threadID] = &sshError{status, code, details}
	delete(s.ssh.bodies, threadID)
}
