package engine

import (
	"errors"

	"github.com/codepagol/isp/backend/internal/routeros"
)

// ErrNotFound means the router had no matching object.
var ErrNotFound = errors.New("router object not found")

// RouterOS paths the engine drives. Kept in one place so it is obvious which
// parts of the PHP MikrotikController this replaces.
const (
	pathPPPSecret       = "/ppp/secret"
	pathPPPActive       = "/ppp/active"
	pathHotspotUser     = "/ip/hotspot/user"
	pathHotspotActive   = "/ip/hotspot/active"
	pathScheduler       = "/system/scheduler"
	schedulerNamePrefix = "hs_expire_"
)

// findID resolves a name filter to the internal ".id" the API needs for
// set/remove, replacing the CLI "[find name=...]" syntax.
func findID(cl *routeros.Client, path, key, value string) (string, error) {
	items, err := cl.Run(path+"/print", nil, routeros.Match(key, value))
	if err != nil {
		return "", err
	}
	for _, item := range items {
		if id := item.Get(".id"); id != "" {
			return id, nil
		}
	}
	return "", nil
}

// removeBy deletes the first object matching key=value. A missing object is not
// an error: an expired user may already be gone.
func removeBy(cl *routeros.Client, path, key, value string) (bool, error) {
	id, err := findID(cl, path, key, value)
	if err != nil {
		return false, err
	}
	if id == "" {
		return false, nil
	}

	if _, err := cl.Run(path+"/remove", map[string]string{".id": id}); err != nil {
		var trap *routeros.TrapError
		// RouterOS reports removing a vanished object as a trap; harmless.
		if errors.As(err, &trap) {
			return false, nil
		}
		return false, err
	}
	return true, nil
}

// expireHotspotOnRouter removes a voucher user, kills its session and clears
// the on-login expiry scheduler, mirroring ExpireRealtimeHotspotUsers.
func expireHotspotOnRouter(cl *routeros.Client, username string) error {
	if _, err := removeBy(cl, pathHotspotUser, "name", username); err != nil {
		return err
	}
	if _, err := removeBy(cl, pathHotspotActive, "user", username); err != nil {
		return err
	}
	_, err := removeBy(cl, pathScheduler, "name", schedulerNamePrefix+username)
	return err
}

// disconnectHotspotOnRouter kicks one active hotspot session.
func disconnectHotspotOnRouter(cl *routeros.Client, username string) error {
	_, err := removeBy(cl, pathHotspotActive, "user", username)
	return err
}

// disconnectPPPOnRouter kicks one active PPPoE session.
func disconnectPPPOnRouter(cl *routeros.Client, username string) error {
	_, err := removeBy(cl, pathPPPActive, "name", username)
	return err
}

// suspendPPPOnRouter ports MikrotikController::disablePPPSecret.
//
// Soft suspension moves the secret to the expired/walled-garden profile and
// kicks the session so the customer reconnects into the restricted pool. If
// that profile does not exist on the router, it falls back to disabling the
// secret outright so the customer is still cut off.
//
// It returns "soft" or "fallback" for audit logging.
func suspendPPPOnRouter(cl *routeros.Client, username, expiredProfile string) (string, error) {
	id, err := findID(cl, pathPPPSecret, "name", username)
	if err != nil {
		return "", err
	}
	if id == "" {
		return "", ErrNotFound
	}

	// Priority 1: soft disable via the expired profile.
	_, softErr := cl.Run(pathPPPSecret+"/set", map[string]string{
		".id":     id,
		"profile": expiredProfile,
	})
	if softErr == nil {
		if err := disconnectPPPOnRouter(cl, username); err != nil {
			return "", err
		}
		if _, err := cl.Run(pathPPPSecret+"/set", map[string]string{
			".id":      id,
			"disabled": "no",
		}); err != nil {
			return "", err
		}
		return "soft", nil
	}

	// Priority 2: hard disable, so a missing "Expired" profile cannot leave the
	// customer online.
	if _, err := cl.Run(pathPPPSecret+"/set", map[string]string{
		".id":      id,
		"disabled": "yes",
	}); err != nil {
		return "", errors.Join(softErr, err)
	}
	if err := disconnectPPPOnRouter(cl, username); err != nil {
		return "", err
	}
	return "fallback", nil
}

// restorePPPOnRouter reverses a suspension: optional profile restore, enable,
// then kick so the customer reconnects with the restored profile.
func restorePPPOnRouter(cl *routeros.Client, username, profile string) error {
	id, err := findID(cl, pathPPPSecret, "name", username)
	if err != nil {
		return err
	}
	if id == "" {
		return ErrNotFound
	}

	args := map[string]string{".id": id, "disabled": "no"}
	if profile != "" {
		args["profile"] = profile
	}
	if _, err := cl.Run(pathPPPSecret+"/set", args); err != nil {
		return err
	}
	return disconnectPPPOnRouter(cl, username)
}
