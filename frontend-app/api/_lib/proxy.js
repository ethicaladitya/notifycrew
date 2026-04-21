function setCors(res) {
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
}

function normalizeBody(body) {
  if (!body) {
    return {};
  }

  if (typeof body === 'string') {
    try {
      return JSON.parse(body);
    } catch {
      return {};
    }
  }

  if (typeof body === 'object') {
    return body;
  }

  return {};
}

async function proxyToWordPress(req, res, route) {
  setCors(res);

  if (req.method === 'OPTIONS') {
    res.status(204).end();
    return;
  }

  if (req.method !== 'POST') {
    res.status(405).json({ success: false, message: 'Method not allowed.' });
    return;
  }

  const wordpressBase = process.env.WORDPRESS_API_BASE || '';
  const apiKey = process.env.TRT_API_KEY || '';

  if (!wordpressBase || !apiKey) {
    res.status(500).json({ success: false, message: 'Proxy environment is not configured.' });
    return;
  }

  const body = normalizeBody(req.body);

  try {
    const response = await fetch(`${wordpressBase.replace(/\/$/, '')}/${route}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-TRT-API-Key': apiKey,
      },
      body: JSON.stringify(body),
    });

    const json = await response.json();

    if (!response.ok) {
      const message = json.message || (json.data && json.data.message) || 'Request failed.';
      res.status(response.status).json({ success: false, message });
      return;
    }

    res.status(200).json({ success: true, data: json });
  } catch (error) {
    res.status(502).json({ success: false, message: 'Upstream request failed.' });
  }
}

module.exports = {
  proxyToWordPress,
};
