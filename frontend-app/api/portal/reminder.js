const { proxyToWordPress } = require('../_lib/proxy');

module.exports = async (req, res) => {
  await proxyToWordPress(req, res, 'reminder');
};
