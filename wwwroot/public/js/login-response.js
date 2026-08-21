(function exposeLoginResponseParser(root) {
  function parseLoginResponse(response) {
    var result = response;
    if (typeof result === 'string') {
      try {
        result = JSON.parse(result);
      } catch (error) {
        throw new Error('Invalid login response');
      }
    }
    if (!result || typeof result !== 'object' || Array.isArray(result)) {
      throw new Error('Invalid login response');
    }
    return result;
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = parseLoginResponse;
  }
  root.parseLoginResponse = parseLoginResponse;
}(typeof window !== 'undefined' ? window : globalThis));
