/**
 * The signal that the server has stopped accepting this session.
 *
 * `apiFetch` cannot call into the auth context — the context is what calls
 * `apiFetch` — so a 401 is announced here and the provider listens. Without it
 * a 401 only removed the token: the provider's `user` stayed set, so the shell
 * never bounced to the login screen, and every figure already in the read cache
 * went on being rendered as though it were current. An administrator who
 * deactivated somebody, or reset their password (which revokes their tokens),
 * left that person looking at the payables and dashboard they had loaded a
 * minute earlier, with each new request failing silently behind it.
 *
 * The server is the enforcement; this is what makes the browser agree with it.
 */
type Listener = () => void;

const listeners = new Set<Listener>();

export function onSessionExpired(listener: Listener): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

export function notifySessionExpired(): void {
  listeners.forEach((listener) => listener());
}
