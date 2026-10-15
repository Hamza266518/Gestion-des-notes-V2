import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
  headers: {
    'Accept': 'application/json',
  },
});

// ---- In-memory GET cache (avoids re-hitting DB on page remounts) ----
const DEFAULT_TTL = 60 * 1000; // 60s
const MAX_CACHE_ENTRIES = 100;
const cache = new Map();

export function clearApiCache(prefix = '') {
  if (!prefix) { cache.clear(); return; }
  for (const key of cache.keys()) {
    if (key.includes(prefix)) cache.delete(key);
  }
}

function cacheKey(config) {
  const method = (config.method || 'get').toLowerCase();
  const params = config.params ? JSON.stringify(config.params) : '';
  const token = localStorage.getItem('token') || '';
  return `${method}:${config.url}:${params}:${token.slice(-8)}`;
}

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  // Let browser set Content-Type for FormData
  if (config.data instanceof FormData) {
    delete config.headers['Content-Type'];
  } else {
    config.headers['Content-Type'] = 'application/json';
  }

  // Serve fresh GET responses from cache (unless disabled or TTL=0)
  if ((config.method || 'get').toLowerCase() === 'get' && config.cache !== false) {
    const key = cacheKey(config);
    const hit = cache.get(key);
    const ttl = typeof config.cacheTtl === 'number' ? config.cacheTtl : DEFAULT_TTL;
    if (hit && Date.now() - hit.at < ttl) {
      // Serve a deep copy so pages mutating state can't corrupt the cache
      let data;
      try {
        data = typeof structuredClone === 'function' ? structuredClone(hit.data) : JSON.parse(JSON.stringify(hit.data));
      } catch {
        data = hit.data;
      }
      config.adapter = () => Promise.resolve({
        data,
        status: 200,
        statusText: 'OK',
        headers: hit.headers,
        config,
        request: {},
      });
      config._fromCache = true;
    } else if (hit) {
      cache.delete(key);
    }
  }
  return config;
});

api.interceptors.response.use(
  (response) => {
    // Persist successful GETs
    const config = response.config;
    const isGet = (config.method || 'get').toLowerCase() === 'get';
    if (isGet && config.cache !== false && !config._fromCache) {
      const ttl = typeof config.cacheTtl === 'number' ? config.cacheTtl : DEFAULT_TTL;
      if (ttl > 0) {
        if (cache.size >= MAX_CACHE_ENTRIES) {
          const oldest = cache.keys().next().value;
          cache.delete(oldest);
        }
        cache.set(cacheKey(config), {
          data: response.data,
          headers: response.headers,
          at: Date.now(),
        });
      }
    }
    // Any mutation invalidates cached reads (data likely changed)
    if (!isGet) {
      cache.clear();
    }
    return response;
  },
  (error) => {
    if (error.response?.status === 401) {
      const url = error.config?.url || '';
      const skipEndpoints = [
        '/auth/login',
        '/auth/me',
        '/auth/password/forgot',
        '/auth/password/reset',
        '/annee-academique/current',
      ];
      const shouldSkip = skipEndpoints.some(ep => url.includes(ep));
      if (shouldSkip) {
        return Promise.reject(error);
      }
      localStorage.removeItem('token');
      localStorage.removeItem('role');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default api;