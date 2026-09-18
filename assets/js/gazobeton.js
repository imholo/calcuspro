(function () {
  'use strict';

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function money(n) {
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency: 'RUB',
      maximumFractionDigits: 0
    }).format(n);
  }

  function num(n, digits) {
    return new Intl.NumberFormat('ru-RU', {
      maximumFractionDigits: digits == null ? 2 : digits
    }).format(n);
  }

  function syncMode() {
    var mode = qs('#mode');
    if (!mode) return;
    var isBox = mode.value === 'box';
    qsa('.mode-perimeter').forEach(function (el) { el.hidden = isBox; });
    qsa('.mode-box').forEach(function (el) { el.hidden = !isBox; });
  }

  function collectPayload(form) {
    var fd = new FormData(form);
    var mode = String(fd.get('mode') || 'perimeter');
    var payload = {
      mode: mode,
      height_m: fd.get('height_m'),
      thickness_mm: fd.get('thickness_mm'),
      openings_m2: fd.get('openings_m2'),
      block_l_mm: fd.get('block_l_mm'),
      block_w_mm: fd.get('block_w_mm'),
      block_h_mm: fd.get('block_h_mm'),
      reserve_pct: fd.get('reserve_pct'),
      price: fd.get('price'),
      price_unit: fd.get('price_unit'),
      glue_kg_m2: fd.get('glue_kg_m2'),
      density: fd.get('density')
    };

    if (mode === 'box') {
      payload.length_m = fd.get('length_m');
      payload.width_m = fd.get('width_m');
    } else {
      payload.perimeter_m = fd.get('perimeter_m');
    }
    return payload;
  }

  function applyPreset(select) {
    var opt = select.options[select.selectedIndex];
    if (!opt || !opt.dataset.l) return;
    qs('#block_l_mm').value = opt.dataset.l;
    qs('#block_w_mm').value = opt.dataset.w;
    qs('#block_h_mm').value = opt.dataset.h;
    if (opt.dataset.thickness) {
      qs('#thickness_mm').value = opt.dataset.thickness;
    }
  }

  function renderResult(data) {
    var r = data.result;
    qs('#out-area').textContent = num(r.wall_area_net_m2, 2) + ' м²';
    qs('#out-volume').textContent = num(r.volume_with_reserve_m3, 3) + ' м³';
    qs('#out-blocks').textContent = num(r.blocks, 0) + ' шт';
    qs('#out-blocks-reserve').textContent = num(r.blocks_with_reserve, 0) + ' шт';
    qs('#out-glue').textContent = num(r.glue_kg, 1) + ' кг';
    qs('#out-mass').textContent = num(r.mass_t, 2) + ' т';
    qs('#out-cost').textContent = money(r.cost);
    qs('#result-card').hidden = false;
  }

  async function onSubmit(event) {
    event.preventDefault();
    var form = event.currentTarget;
    var btn = qs('[type="submit"]', form);
    var err = qs('#form-error');
    err.textContent = '';
    btn.disabled = true;

    try {
      var res = await fetch('/api/gazobeton.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(collectPayload(form))
      });

      var data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error((data && data.error) || 'Ошибка расчёта');
      }
      renderResult(data);
    } catch (e) {
      err.textContent = e.message || 'Не удалось выполнить расчёт';
    } finally {
      btn.disabled = false;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var mode = qs('#mode');
    if (mode) {
      mode.addEventListener('change', syncMode);
      syncMode();
    }

    var preset = qs('#block_preset');
    if (preset) {
      preset.addEventListener('change', function () { applyPreset(preset); });
      applyPreset(preset);
    }

    var form = qs('#gazobeton-form');
    if (form) form.addEventListener('submit', onSubmit);
  });
})();
