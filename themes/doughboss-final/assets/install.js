/* Online-only browser installation: no worker, offline cache or saved state. */
(function () {
  'use strict';
  var button = document.querySelector('[data-db-install]');
  var status = document.querySelector('[data-db-install-status]');
  if (!button || !status) return;
  var deferred = null;
  var mode = window.matchMedia ? window.matchMedia('(display-mode: standalone)') : null;
  var installed = !!((mode && mode.matches) || window.navigator.standalone === true);
  button.hidden = true;

  function installedState() {
    installed = true;
    deferred = null;
    button.hidden = true;
    button.disabled = true;
    status.textContent = 'Dough Boss is already open as an installed app. An internet connection is required.';
  }
  if (installed) installedState();

  window.addEventListener('beforeinstallprompt', function (event) {
    if (!event.isTrusted || installed || typeof event.prompt !== 'function' || !event.userChoice || typeof event.userChoice.then !== 'function') return;
    event.preventDefault();
    deferred = event;
    button.disabled = false;
    button.hidden = false;
    status.textContent = 'Your browser can install Dough Boss. An internet connection is required.';
  });

  button.addEventListener('click', async function () {
    if (!deferred || installed) return;
    var prompt = deferred;
    deferred = null;
    button.hidden = true;
    button.disabled = true;
    try {
      await prompt.prompt();
      var choice = await prompt.userChoice;
      if (installed) return;
      status.textContent = choice && choice.outcome === 'accepted'
        ? 'Installation requested. Your browser will finish the installation. An internet connection is required.'
        : 'Installation dismissed. You can keep using the website or follow the browser instructions below.';
    } catch (error) {
      if (!installed) status.textContent = 'Installation is not available right now. Keep using the website or follow the browser instructions below.';
    }
  });

  window.addEventListener('appinstalled', function (event) {
    if (!event.isTrusted) return;
    installedState();
    status.textContent = 'Dough Boss was installed. An internet connection is required.';
  });
  if (mode && typeof mode.addEventListener === 'function') {
    mode.addEventListener('change', function () { if (mode.matches) installedState(); });
  }
}());
