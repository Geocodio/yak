package api

// Wire types for /api/cli/v1. Unknown response fields are ignored by encoding/json.

type DeviceCodeRequest struct {
	Name       string `json:"name"`
	OS         string `json:"os"`
	Arch       string `json:"arch"`
	CLIVersion string `json:"cli_version"`
}

type DeviceCode struct {
	DeviceCode              string `json:"device_code"`
	UserCode                string `json:"user_code"`
	VerificationURL         string `json:"verification_url"`
	VerificationURLComplete string `json:"verification_url_complete"`
	ExpiresIn               int    `json:"expires_in"`
	Interval                int    `json:"interval"`
}

type DeviceInfo struct {
	ID                   int    `json:"id"`
	Name                 string `json:"name"`
	OS                   string `json:"os"`
	Arch                 string `json:"arch"`
	CreatedAt            string `json:"created_at,omitempty"`
	LastSeenAt           string `json:"last_seen_at,omitempty"`
	LastTeleportThreadID *int   `json:"last_teleport_thread_id,omitempty"`
}

type User struct {
	ID    int    `json:"id"`
	Name  string `json:"name"`
	Email string `json:"email"`
}

type TokenResponse struct {
	Token  string     `json:"token"`
	Device DeviceInfo `json:"device"`
	User   User       `json:"user"`
}

type Me struct {
	Device   DeviceInfo `json:"device"`
	User     User       `json:"user"`
	Protocol struct {
		Current int `json:"current"`
		Min     int `json:"min"`
	} `json:"protocol"`
	BaseURL string `json:"base_url"`
}

type ResolveRequest struct {
	Remotes []string `json:"remotes"`
}

type ResolveItem struct {
	Remote        string  `json:"remote"`
	OnYak         bool    `json:"on_yak"`
	Repo          *string `json:"repo"`
	DefaultBranch *string `json:"default_branch"`
	CloneURL      *string `json:"clone_url"`
}

type ResolveResult struct {
	Repos []ResolveItem `json:"repos"`
}

type ClaudeAccount struct {
	Name string `json:"name"`
}

type OwnerDevice struct {
	ID           int    `json:"id"`
	Name         string `json:"name"`
	IsThisDevice bool   `json:"is_this_device"`
}

type ThreadRepo struct {
	Repo        string `json:"repo"`
	Role        string `json:"role"`
	Branch      string `json:"branch"`
	SandboxPath string `json:"sandbox_path"`
}

type LastTeleportUp struct {
	TeleportID string `json:"teleport_id"`
	At         string `json:"at"`
	Branch     string `json:"branch"`
	HeadSHA    string `json:"head_sha"`
	DeviceID   int    `json:"device_id"`
}

type OpenTeleport struct {
	TeleportID string `json:"teleport_id"`
	Direction  string `json:"direction"`
	DeviceID   int    `json:"device_id"`
	State      string `json:"state"`
}

type Thread struct {
	ID                    int             `json:"id"`
	Title                 string          `json:"title"`
	Repo                  string          `json:"repo"`
	Branch                string          `json:"branch"`
	Status                string          `json:"status"`
	Visibility            string          `json:"visibility"`
	CreatedByUserID       int             `json:"created_by_user_id"`
	ContinuedFromThreadID *int            `json:"continued_from_thread_id"`
	OwnerSide             string          `json:"owner_side"`
	OwnerDevice           *OwnerDevice    `json:"owner_device"`
	WaitingForComputer    bool            `json:"waiting_for_computer"`
	TurnRunning           bool            `json:"turn_running"`
	Teleport              *OpenTeleport   `json:"teleport"`
	ClaudeAccount         ClaudeAccount   `json:"claude_account"`
	ActiveSessionID       string          `json:"active_session_id"`
	LastTeleportUp        *LastTeleportUp `json:"last_teleport_up"`
	HeldEventCount        int             `json:"held_event_count"`
	Repos                 []ThreadRepo    `json:"repos"`
	URL                   string          `json:"url"`
}

// ThreadResponse is the body of GET /threads/{id}.
type ThreadResponse struct {
	Thread Thread `json:"thread"`
}

type Progress struct {
	Step   string `json:"step"`
	Status string `json:"status"`
}

type Failure struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

type Teleport struct {
	TeleportID       string     `json:"teleport_id"`
	ThreadID         int        `json:"thread_id"`
	Direction        string     `json:"direction"`
	State            string     `json:"state"`
	DeviceID         int        `json:"device_id"`
	CreatedAt        string     `json:"created_at"`
	UpdatedAt        string     `json:"updated_at"`
	ExpiresAt        string     `json:"expires_at"`
	PollAfterSeconds int        `json:"poll_after_seconds"`
	Progress         []Progress `json:"progress"`
	Failure          *Failure   `json:"failure"`
}

type BeginRepo struct {
	Repo            string  `json:"repo"`
	Role            string  `json:"role"`
	LocalPath       string  `json:"local_path"`
	Branch          *string `json:"branch,omitempty"`
	OnDefaultBranch bool    `json:"on_default_branch,omitempty"`
	HeadSHA         *string `json:"head_sha,omitempty"`
}

type BeginUpRequest struct {
	Direction          string      `json:"direction"`
	SessionID          string      `json:"session_id"`
	TitleHint          string      `json:"title_hint"`
	GitName            string      `json:"git_name"`
	GitEmail           string      `json:"git_email"`
	LocalPath          string      `json:"local_path"`
	OS                 string      `json:"os"`
	Arch               string      `json:"arch"`
	Repos              []BeginRepo `json:"repos"`
	MissingDirectories []string    `json:"missing_directories"`
}

type BeginUpThread struct {
	ID                    int           `json:"id"`
	IsNew                 bool          `json:"is_new"`
	ContinuedFromThreadID *int          `json:"continued_from_thread_id"`
	Slug                  string        `json:"slug"`
	Visibility            string        `json:"visibility"`
	FirstTeleportedAt     *string       `json:"first_teleported_at"`
	ClaudeAccount         ClaudeAccount `json:"claude_account"`
	URL                   string        `json:"url"`
}

type BeginUpRepo struct {
	Repo         string `json:"repo"`
	Role         string `json:"role"`
	Branch       string `json:"branch"`
	CreateBranch bool   `json:"create_branch"`
	CloneURL     string `json:"clone_url"`
	SandboxPath  string `json:"sandbox_path"`
}

type BeginUpResponse struct {
	TeleportID string        `json:"teleport_id"`
	Resumed    bool          `json:"resumed"`
	Thread     BeginUpThread `json:"thread"`
	Repos      []BeginUpRepo `json:"repos"`
	ExpiresAt  string        `json:"expires_at"`
}

type BeginDownRequest struct {
	Direction   string      `json:"direction"`
	ThreadID    int         `json:"thread_id"`
	LocalPath   string      `json:"local_path"`
	OS          string      `json:"os"`
	Arch        string      `json:"arch"`
	Repos       []BeginRepo `json:"repos,omitempty"`
	RunningTurn string      `json:"running_turn,omitempty"`
	Visibility  string      `json:"visibility,omitempty"`
}

type BeginDownResponse struct {
	TeleportID string   `json:"teleport_id"`
	Resumed    bool     `json:"resumed"`
	Teleport   Teleport `json:"teleport"`
}

type CompleteUpRepo struct {
	Repo    string `json:"repo"`
	Branch  string `json:"branch"`
	HeadSHA string `json:"head_sha"`
}

type CompleteUpRequest struct {
	Repos   []CompleteUpRepo `json:"repos"`
	Message string           `json:"message,omitempty"`
}

type Marker struct {
	ThreadID         int    `json:"thread_id"`
	TeleportID       string `json:"teleport_id"`
	Owner            string `json:"owner"`
	ThreadURL        string `json:"thread_url,omitempty"`
	StackMessagesURL string `json:"stack_messages_url,omitempty"`
}

// SandboxRepo is how one repo fared when Yak prepared the sandbox. Reason is empty unless Status is failed.
type SandboxRepo struct {
	Repo   string `json:"repo"`
	Role   string `json:"role"`
	Status string `json:"status"`
	Reason string `json:"reason"`
}

type Sandbox struct {
	State   string        `json:"state"`
	Message *string       `json:"message"`
	Repos   []SandboxRepo `json:"repos,omitempty"`
}

type CompleteUpResponse struct {
	Teleport            Teleport `json:"teleport"`
	Ownership           string   `json:"ownership"`
	Sandbox             Sandbox  `json:"sandbox"`
	TurnStarted         bool     `json:"turn_started"`
	HeldEventsDelivered int      `json:"held_events_delivered"`
	Marker              Marker   `json:"marker"`
}

type CancelRequest struct {
	Reason string `json:"reason"`
	Detail string `json:"detail,omitempty"`
}

type SessionFile struct {
	RelPath string `json:"rel_path"`
	Mode    string `json:"mode,omitempty"`
	Length  int64  `json:"length"`
	SHA256  string `json:"sha256"`
}

type ManifestRepo struct {
	Repo               string `json:"repo"`
	Role               string `json:"role"`
	CloneURL           string `json:"clone_url"`
	DefaultBranch      string `json:"default_branch"`
	Branch             string `json:"branch"`
	HeadSHA            string `json:"head_sha"`
	BranchCreatedByYak bool   `json:"branch_created_by_yak"`
	SandboxPath        string `json:"sandbox_path"`
	WIPCommitMade      bool   `json:"wip_commit_made"`
	Pushed             bool   `json:"pushed"`
	// Status is "ready" or "failed"; empty counts as ready.
	Status  string        `json:"status,omitempty"`
	Failure *Failure      `json:"failure,omitempty"`
	Refresh []RefreshRule `json:"refresh,omitempty"`
}

// RefreshRule is one entry of a repo's refresh map.
type RefreshRule struct {
	Glob    string `json:"glob"`
	Command string `json:"command"`
}

type MemoryFile struct {
	RelPath                  string `json:"rel_path"`
	Length                   int64  `json:"length"`
	SHA256                   string `json:"sha256"`
	ChangedSinceLastTeleport bool   `json:"changed_since_last_teleport"`
}

// DownLastUp is the state of the thread's last teleport up, the baseline for change detection.
type DownLastUp struct {
	TeleportID string        `json:"teleport_id"`
	Branch     string        `json:"branch"`
	HeadSHA    string        `json:"head_sha"`
	Files      []SessionFile `json:"files"`
}

type DownManifest struct {
	Teleport Teleport `json:"teleport"`
	Thread   struct {
		ID            int           `json:"id"`
		Slug          string        `json:"slug"`
		Title         string        `json:"title"`
		Visibility    string        `json:"visibility"`
		ClaudeAccount ClaudeAccount `json:"claude_account"`
	} `json:"thread"`
	Repos []ManifestRepo `json:"repos"`
	// ContextRepos are the slugs of context repos that were not brought down.
	ContextRepos []string    `json:"context_repos,omitempty"`
	LastUp       *DownLastUp `json:"last_up"`
	Session      struct {
		SessionID       string        `json:"session_id"`
		Files           []SessionFile `json:"files"`
		OtherSessionIDs []string      `json:"other_session_ids"`
	} `json:"session"`
	Memory      []MemoryFile `json:"memory"`
	Environment struct {
		SandboxOS            string `json:"sandbox_os"`
		SandboxArch          string `json:"sandbox_arch"`
		SandboxWorkspacePath string `json:"sandbox_workspace_path"`
		ExtrasPathPattern    string `json:"extras_path_pattern"`
		Refresh              []struct {
			Glob    string `json:"glob"`
			Command string `json:"command"`
		} `json:"refresh"`
	} `json:"environment"`
}

type AppliedRepo struct {
	Repo    string `json:"repo"`
	Status  string `json:"status"`
	Mode    string `json:"mode,omitempty"`
	HeadSHA string `json:"head_sha,omitempty"`
	Branch  string `json:"branch,omitempty"`
	Path    string `json:"path,omitempty"`
	Reason  string `json:"reason,omitempty"`
}

type DownloadedFile struct {
	SessionID string `json:"session_id"`
	RelPath   string `json:"rel_path"`
	SHA256    string `json:"sha256"`
}

type CompleteDownRequest struct {
	Applied            []AppliedRepo    `json:"applied"`
	Downloaded         []DownloadedFile `json:"downloaded"`
	LocalSessionBackup string           `json:"local_session_backup,omitempty"`
}

type CompleteDownResponse struct {
	Teleport    Teleport     `json:"teleport"`
	Ownership   string       `json:"ownership"`
	OwnerDevice *OwnerDevice `json:"owner_device"`
	Visibility  string       `json:"visibility"`
	Marker      Marker       `json:"marker"`
}

type StackMessage struct {
	ID         int    `json:"id"`
	Kind       string `json:"kind"`
	FromThread struct {
		ID    int    `json:"id"`
		Title string `json:"title"`
		Kind  string `json:"kind"`
	} `json:"from_thread"`
	Text      string `json:"text"`
	CreatedAt string `json:"created_at"`
}
