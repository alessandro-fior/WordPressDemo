(function () {
  var btn = document.getElementById('af-ai-run');
  if (!btn) return;
  var out = document.getElementById('af-ai-out');
  var spin = document.getElementById('af-ai-spin');

  function show(html) { out.textContent = ''; out.appendChild(html); }
  function p(label, text) {
    var el = document.createElement('p');
    var b = document.createElement('strong');
    b.textContent = label + ' ';
    el.appendChild(b);
    el.appendChild(document.createTextNode(text));
    return el;
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    spin.classList.add('is-active');
    var data = new URLSearchParams({
      action: 'af_ai_run',
      nonce: AFAI.nonce,
      post_id: btn.dataset.post
    });
    fetch(AFAI.ajax, { method: 'POST', credentials: 'same-origin', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var box = document.createElement('div');
        if (res.success) {
          box.appendChild(p('Riassunto:', res.data.riassunto));
          box.appendChild(p('Categoria suggerita:', res.data.categoria));
          box.appendChild(p('Tag aggiunti:', res.data.tag.join(', ')));
        } else {
          box.appendChild(p('Errore:', (res.data && res.data.msg) || 'richiesta non riuscita'));
        }
        show(box);
      })
      .catch(function () {
        var box = document.createElement('div');
        box.appendChild(p('Errore:', 'connessione non riuscita'));
        show(box);
      })
      .finally(function () {
        btn.disabled = false;
        spin.classList.remove('is-active');
      });
  });
})();
