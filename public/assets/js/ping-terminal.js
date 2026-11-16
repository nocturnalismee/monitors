/**
 * Ping Terminal – terminal-style modal for on-demand ICMP ping (SSE streaming).
 *
 * The stream is read with fetch() instead of EventSource so the CSRF token can
 * travel in an X-CSRF-Token header rather than the query string (which would
 * leak it into access logs, Referer headers and browser history).
 */
(function () {
  'use strict';

  var modalEl = document.getElementById('pingTerminalModal');
  if (!modalEl) return;

  var outputEl = document.getElementById('pingTerminalOutput');
  var cmdInput = document.getElementById('pingTerminalCmd');
  var csrfToken = window.MONITORS_CSRF_TOKEN || '';
  var streamUrl = window.MONITORS_PING_STREAM_URL || '/ping/terminal/stream';
  var controller = null;
  var finished = true;

  function writeLine(text, cls) {
    var line = document.createElement('div');
    line.className = 'terminal-line' + (cls ? ' ' + cls : '');
    line.textContent = String(text);
    outputEl.appendChild(line);
    outputEl.scrollTop = outputEl.scrollHeight;
  }

  function writeCommand(cmd) {
    var line = document.createElement('div');
    line.className = 'terminal-line terminal-cmd';
    var prompt = document.createElement('span');
    prompt.className = 'terminal-prompt-inline';
    prompt.textContent = 'admin@monitors:~$';
    line.appendChild(prompt);
    line.appendChild(document.createTextNode(' ' + cmd));
    outputEl.appendChild(line);
    outputEl.scrollTop = outputEl.scrollHeight;
  }

  function setRunning(running) {
    finished = !running;
    cmdInput.disabled = running;
    if (!running) cmdInput.focus();
  }

  function reset() {
    outputEl.innerHTML = '';
    cmdInput.value = '';
    setRunning(false);
  }

  function abortStream() {
    if (controller) {
      controller.abort();
      controller = null;
    }
  }

  function finish() {
    abortStream();
    setRunning(false);
  }

  /**
   * Parse an SSE byte stream into events: blocks separated by a blank line,
   * each carrying `event:` and one or more `data:` lines.
   */
  function createSseParser(onEvent) {
    var buffer = '';
    return function feed(chunk) {
      buffer += chunk;
      var boundary;
      while ((boundary = buffer.indexOf('\n\n')) !== -1) {
        var block = buffer.slice(0, boundary);
        buffer = buffer.slice(boundary + 2);
        var eventName = 'message';
        var dataLines = [];
        block.split('\n').forEach(function (line) {
          if (line.indexOf('event:') === 0) {
            eventName = line.slice(6).trim();
          } else if (line.indexOf('data:') === 0) {
            dataLines.push(line.slice(5).replace(/^ /, ''));
          }
        });
        if (dataLines.length > 0) {
          onEvent(eventName, dataLines.join('\n'));
        }
      }
    };
  }

  function handleEvent(eventName, rawData) {
    var data = {};
    try { data = JSON.parse(rawData); } catch (err) { data = {}; }
    if (eventName === 'end') {
      if (data.exit_code !== undefined) {
        writeLine('[process exited with code ' + data.exit_code + ']', 'terminal-muted');
      }
      finish();
      return;
    }
    if (data.line !== undefined) {
      writeLine(data.line);
    }
  }

  function runCommand(cmd) {
    setRunning(true);
    writeCommand(cmd);
    abortStream();

    var local = new AbortController();
    controller = local;
    var url = streamUrl + '?cmd=' + encodeURIComponent(cmd);
    var parser = createSseParser(handleEvent);

    fetch(url, {
      method: 'GET',
      headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'text/event-stream' },
      credentials: 'same-origin',
      signal: local.signal,
    })
      .then(function (response) {
        if (!response.ok || !response.body) {
          throw new Error('HTTP ' + response.status);
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        function pump() {
          return reader.read().then(function (result) {
            if (result.done) return;
            parser(decoder.decode(result.value, { stream: true }));
            return pump();
          });
        }
        return pump();
      })
      .then(function () {
        if (!finished) finish();
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        if (!finished) {
          writeLine('[connection closed]', 'terminal-muted');
          finish();
        }
      });
  }

  modalEl.addEventListener('show.bs.modal', reset);
  modalEl.addEventListener('hidden.bs.modal', abortStream);

  cmdInput.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var cmd = cmdInput.value.trim();
    if (!cmd || cmdInput.disabled) return;
    cmdInput.value = '';
    runCommand(cmd);
  });
})();
