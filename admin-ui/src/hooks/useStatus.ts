import { useState, useEffect, useCallback } from '@wordpress/element';
import { fetchStatus, StatusResponse } from '../lib/api';

const POLL_INTERVAL_MS = 30_000;

export function useStatus() {
  const [status, setStatus]   = useState<StatusResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState<string | null>(null);

  const refresh = useCallback(async () => {
    try {
      const data = await fetchStatus();
      setStatus(data);
      setError(null);
    } catch {
      setError('Failed to load status. Check your connection.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
    const timer = setInterval(refresh, POLL_INTERVAL_MS);
    return () => clearInterval(timer);
  }, [refresh]);

  return { status, loading, error, refresh };
}
