/**
 * Ping Terminal – terminal-style modal for on-demand ICMP ping (SSE streaming).
 */
(function () {
  'use strict';

  var modalEl = document.getElementById('pingTerminalModal');
  if (!modalEl) return;

  var outputEl = document.getElementById('pingTerminalOutput');
  var cmdInput = document.getElementById('pingTerminalCmd');
  var csrfToken = window.SERVMON_CSRF_TOKEN || '';
  var currentEs = null;
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
    prompt.textContent = 'admin@servmon:~$';
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

  function finish() {
    if (currentEs) {
      currentEs.close();
      currentEs = null;
    }
    setRunning(false);
  }

  function runCommand(cmd) {
    setRunning(true);
    writeCommand(cmd);

    var params = new URLSearchParams();
    params.set('cmd', cmd);
    params.set('token', csrfToken);

    currentEs = new EventSource('/ping/terminal/stream?' + params.toString());
    finished = false;

    currentEs.addEventListener('message', function (e) {
      var data = {};
      try { data = JSON.parse(e.data); } catch (err) { data = {}; }
      if (data.line !== undefined) {
        writeLine(data.line);
      }
    });

    currentEs.addEventListener('end', function (e) {
      var data = {};
      try { data = JSON.parse(e.data); } catch (err) { data = {}; }
      if (data.exit_code !== undefined) {
        writeLine('[process exited with code ' + data.exit_code + ']', 'terminal-muted');
      }
      finish();
    });

    currentEs.onerror = function () {
      if (finished) return;
      writeLine('[connection closed]', 'terminal-muted');
      finish();
    };
  }

  modalEl.addEventListener('show.bs.modal', reset);
  modalEl.addEventListener('hidden.bs.modal', function () {
    if (currentEs) {
      currentEs.close();
      currentEs = null;
    }
  });

  cmdInput.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var cmd = cmdInput.value.trim();
    if (!cmd || cmdInput.disabled) return;
    cmdInput.value = '';
    runCommand(cmd);
  });
})();
