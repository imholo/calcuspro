(function () {
  'use strict';

  var openings = [
    { w: 1.5, h: 1.4, n: 8 },
    { w: 1.0, h: 2.1, n: 1 }
  ];
  var lastPayload = null;

  var BINDER_UI = {
    glue: {
      material: 'клей для газобетона',
      priceLabel: 'Цена мешка клея, ₽',
      defaultPrice: 450,
      qtyLabel: 'Клей, расход',
      packsLabel: 'Мешки по 25 кг',
      costLabel: 'Клей, сумма',
      hint: 'Клей: шов 2–3 мм, ~25 кг/м³, мешок 25 кг. В палете 40 блоков 300×250×600 (1,8 м³).'
    },
    cps: {
      material: 'ЦПС М300',
      priceLabel: 'Цена мешка смеси М300, ₽',
      defaultPrice: 280,
      qtyLabel: 'ЦПС М300, расход',
      packsLabel: 'Мешки по 50 кг',
      costLabel: 'ЦПС М300, сумма',
      hint: 'ЦПС М300: шов ~10–12 мм, ~18 кг/м² стены, мешок 50 кг.'
    },
    foam: {
      material: 'клей-пена 750 мл',
      priceLabel: 'Цена баллона пены, ₽',
      defaultPrice: 550,
      qtyLabel: 'Клей-пена, расход',
      packsLabel: 'Баллоны 750 мл',
      costLabel: 'Клей-пена, сумма',
      hint: 'Клей-пена: баллон 750 мл, ~1 шт на 1 м³ кладки.'
    }
  };

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

  function numFmt(n, digits) {
    return new Intl.NumberFormat('ru-RU', {
      maximumFractionDigits: digits == null ? 2 : digits,
      minimumFractionDigits: 0
    }).format(n);
  }

  function parseNum(value) {
    if (typeof value === 'number') return value;
    var s = String(value == null ? '' : value).trim().replace(/\s/g, '').replace(',', '.');
    if (s === '' || s === '.' || s === '-') return NaN;
    return Number(s);
  }

  function sanitizeDecimalInput(el) {
    var raw = String(el.value || '');
    var cleaned = raw.replace(/[^\d.,]/g, '');
    var sep = '';
    var out = '';
    for (var i = 0; i < cleaned.length; i++) {
      var ch = cleaned.charAt(i);
      if (ch === ',' || ch === '.') {
        if (sep) continue;
        sep = ch;
        out += ch;
      } else {
        out += ch;
      }
    }
    if (out !== raw) el.value = out;
  }

  function bindDecimalFields(root) {
    qsa('input.decimal', root || document).forEach(function (el) {
      if (el.dataset.decimalBound === '1') return;
      el.dataset.decimalBound = '1';
      el.addEventListener('input', function () { sanitizeDecimalInput(el); });
    });
  }

  function escapeAttr(v) {
    return String(v)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function openingValue(n) {
    if (!isFinite(n)) return '';
    return escapeAttr(formatDecimal(n, 3));
  }

  function selectedRadio(name) {
    var el = qs('input[name="' + name + '"]:checked');
    return el ? el.value : '';
  }

  function formatDecimal(n, digits) {
    if (!isFinite(n)) return '';
    var d = digits == null ? 3 : digits;
    var s = Number(n).toFixed(d).replace(/\.?0+$/, '');
    return s.replace('.', ',');
  }

  function syncPerimeterFromBox() {
    var mode = selectedRadio('wall_mode') || 'box';
    var perEl = qs('#perimeter_m');
    if (!perEl || mode !== 'box') return;

    var w = parseNum(qs('#width_m') && qs('#width_m').value);
    var l = parseNum(qs('#length_m') && qs('#length_m').value);
    if (!isFinite(w) || !isFinite(l)) {
      perEl.value = '';
      return;
    }
    perEl.value = formatDecimal(2 * (w + l), 3);
  }

  function syncWallMode() {
    var mode = selectedRadio('wall_mode') || 'box';
    var wrapW = qs('#wrap-width');
    var wrapL = qs('#wrap-length');
    var perEl = qs('#perimeter_m');
    var isBox = mode === 'box';

    if (wrapW) wrapW.hidden = !isBox;
    if (wrapL) wrapL.hidden = !isBox;

    if (perEl) {
      perEl.disabled = isBox;
      perEl.readOnly = isBox;
      if (isBox) {
        syncPerimeterFromBox();
      }
    }
  }

  function syncBinderUi() {
    var mode = selectedRadio('binder_mode') || 'glue';
    var cfg = BINDER_UI[mode] || BINDER_UI.glue;
    var labelText = qs('#binder-price-text');
    var materialName = qs('#binder-material-name');
    var price = qs('#binder_price');
    var hint = qs('#binder-hint');
    if (labelText) labelText.textContent = cfg.priceLabel;
    if (materialName) materialName.textContent = cfg.material;
    if (price && !price.dataset.touched) price.value = String(cfg.defaultPrice);
    if (hint) hint.textContent = cfg.hint;

    var qtyLabel = qs('#out-binder-qty-label');
    var packsLabel = qs('#out-binder-packs-label');
    var costLabel = qs('#out-binder-cost-label');
    if (qtyLabel) qtyLabel.textContent = cfg.qtyLabel;
    if (packsLabel) packsLabel.textContent = cfg.packsLabel;
    if (costLabel) costLabel.textContent = cfg.costLabel;
  }

  function renderOpenings() {
    var box = qs('#openings');
    if (!box) return;
    if (!openings.length) {
      box.innerHTML = '<p class="text-sm text-gray-400">Проёмов нет. Нажмите «+ проём», если нужно вычесть окна или двери.</p>';
      return;
    }
    box.innerHTML = openings.map(function (o, i) {
      return (
        '<div class="cp-opening" data-opening="' + i + '">' +
          '<div class="cp-opening-grid">' +
            '<label class="cp-field-wrap"><span>Ширина, м</span>' +
              '<input class="decimal cp-field cp-input" data-i="' + i + '" data-k="w" type="text" inputmode="decimal" autocomplete="off" value="' + openingValue(o.w) + '" /></label>' +
            '<label class="cp-field-wrap"><span>Высота, м</span>' +
              '<input class="decimal cp-field cp-input" data-i="' + i + '" data-k="h" type="text" inputmode="decimal" autocomplete="off" value="' + openingValue(o.h) + '" /></label>' +
            '<label class="cp-field-wrap"><span>Кол-во</span>' +
              '<input class="cp-field cp-input" data-i="' + i + '" data-k="n" type="text" inputmode="numeric" autocomplete="off" value="' + escapeAttr(isFinite(o.n) ? String(Math.round(o.n)) : '1') + '" /></label>' +
            '<button type="button" class="cp-del" data-del="' + i + '" aria-label="Удалить проём">Удалить</button>' +
          '</div>' +
        '</div>'
      );
    }).join('');
    bindDecimalFields(box);
  }

  function syncOpeningsFromDom() {
    var box = qs('#openings');
    if (!box) return;
    openings.forEach(function (o, i) {
      var w = qs('input[data-i="' + i + '"][data-k="w"]', box);
      var h = qs('input[data-i="' + i + '"][data-k="h"]', box);
      var n = qs('input[data-i="' + i + '"][data-k="n"]', box);
      if (w) o.w = parseNum(w.value);
      if (h) o.h = parseNum(h.value);
      if (n) o.n = parseNum(n.value);
    });
  }

  function validatedOpenings() {
    syncOpeningsFromDom();
    var clean = [];
    for (var i = 0; i < openings.length; i++) {
      var o = openings[i];
      var w = o.w;
      var h = o.h;
      var n = o.n;
      if (!isFinite(w) || !isFinite(h) || !isFinite(n)) {
        throw new Error('Проём ' + (i + 1) + ': укажите ширину, высоту и количество (можно 1,5)');
      }
      if (w <= 0 || h <= 0) {
        throw new Error('Проём ' + (i + 1) + ': ширина и высота должны быть больше 0');
      }
      var nInt = Math.round(n);
      if (Math.abs(n - nInt) > 1e-9 || nInt < 1) {
        throw new Error('Проём ' + (i + 1) + ': количество должно быть целым числом ≥ 1');
      }
      clean.push({ w: w, h: h, n: nInt });
    }
    openings = clean;
    return clean;
  }

  function collectPayload(form) {
    var fd = new FormData(form);
    var wallMode = selectedRadio('wall_mode') || 'box';
    var cleanOpenings = validatedOpenings();
    var payload = {
      wall_mode: wallMode,
      height1_m: parseNum(fd.get('height1_m')),
      height2_m: parseNum(fd.get('height2_m')),
      density: parseNum(selectedRadio('density') || '400'),
      block_t_mm: parseNum(fd.get('block_t_mm')),
      block_h_mm: parseNum(fd.get('block_h_mm')),
      block_l_mm: parseNum(fd.get('block_l_mm')),
      waste_pct: parseNum(fd.get('waste_pct')),
      price: parseNum(fd.get('price')),
      price_mode: String(fd.get('price_mode') || 'm3'),
      binder_mode: String(selectedRadio('binder_mode') || 'glue'),
      binder_price: parseNum(fd.get('binder_price')),
      openings: cleanOpenings
    };

    if (wallMode === 'perimeter') {
      payload.perimeter_m = parseNum(fd.get('perimeter_m'));
    } else {
      payload.width_m = parseNum(fd.get('width_m'));
      payload.length_m = parseNum(fd.get('length_m'));
    }
    return payload;
  }

  function renderResult(data) {
    var r = data.result;
    var b = r.binder || {};
    qs('#out-cost').textContent = money(r.cost);
    qs('#out-vol').textContent = numFmt(r.volume_m3, 2) + ' м³';
    qs('#out-net').textContent = numFmt(r.volume_net_m3, 2) + ' м³';
    qs('#out-pcs').textContent = numFmt(r.blocks, 0) + ' шт.';
    qs('#out-pallets').textContent = String(r.pallets);
    qs('#out-mass').textContent = numFmt(r.mass_t, 2) + ' т (D' + r.density + ')';
    qs('#out-blocks-m3').textContent = money(r.blocks_price_per_m3) + ' / м³';
    qs('#out-blocks-cost').textContent = money(r.blocks_cost);
    qs('#out-binder-qty-label').textContent = (b.label || 'Материал') + ', расход';
    qs('#out-binder-qty').textContent = numFmt(b.qty, b.qty_unit === 'cylinder' ? 0 : 1) + ' ' + (b.qty_label || '');
    qs('#out-binder-packs-label').textContent = b.pack_label || 'Упаковки';
    qs('#out-binder-packs').textContent = String(b.packs == null ? '—' : b.packs);
    var unitLabel = qs('#out-binder-unit-label');
    if (unitLabel) {
      unitLabel.textContent = 'Цена за ' + (b.unit_label || 'шт');
    }
    qs('#out-binder-unit').textContent = money(b.unit_price || 0) + ' / шт';
    var costLabel = qs('#out-binder-cost-label');
    if (costLabel) costLabel.textContent = (b.label || 'Материал') + ', сумма';
    qs('#out-binder-cost').textContent = money(b.cost || 0);
    qs('#out-hint').textContent =
      'Периметр ' + numFmt(r.perimeter_m, 2) + ' м · площадь стен ' +
      numFmt(r.wall_area_m2, 2) + ' м² · проёмы ' + numFmt(r.openings_m2, 2) + ' м²' +
      (b.note ? ' · ' + b.note : '') + '.';
    qs('#result-card').hidden = false;
    setExportEnabled(true);
  }

  function setExportEnabled(on) {
    ['#export-xls', '#export-pdf'].forEach(function (sel) {
      var el = qs(sel);
      if (el) el.disabled = !on;
    });
  }

  function filenameFromDisposition(header, fallback) {
    if (!header) return fallback;
    var m = /filename=\"?([^\";]+)\"?/i.exec(header);
    return m ? m[1] : fallback;
  }

  async function exportResult(format) {
    var err = qs('#export-error');
    if (err) {
      err.hidden = true;
      err.textContent = '';
    }
    if (!lastPayload) {
      if (err) {
        err.hidden = false;
        err.textContent = 'Сначала выполните расчёт';
      }
      return;
    }

    var btn = qs(format === 'pdf' ? '#export-pdf' : '#export-xls');
    if (btn) btn.disabled = true;

    try {
      var payload = Object.assign({}, lastPayload, { format: format });
      var res = await fetch('/api/gazobeton_export.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': format === 'pdf' ? 'application/pdf' : 'application/vnd.ms-excel'
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });

      var ctype = (res.headers.get('Content-Type') || '').toLowerCase();
      if (!res.ok || ctype.indexOf('json') !== -1) {
        var data = null;
        try { data = await res.json(); } catch (e) {}
        throw new Error((data && data.error) || 'Не удалось сформировать файл');
      }

      var blob = await res.blob();
      var name = filenameFromDisposition(
        res.headers.get('Content-Disposition'),
        'gazobeton.' + format
      );
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = name;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
    } catch (e) {
      if (err) {
        err.hidden = false;
        err.textContent = e.message || 'Ошибка экспорта';
      }
    } finally {
      if (btn) btn.disabled = !lastPayload;
    }
  }

  async function onSubmit(event) {
    event.preventDefault();
    var form = event.currentTarget;
    var btn = qs('[type="submit"]', form);
    var err = qs('#form-error');
    err.textContent = '';
    btn.disabled = true;

    try {
      var payload = collectPayload(form);
      var res = await fetch('/api/gazobeton.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });
      var data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error((data && data.error) || 'Ошибка расчёта');
      }
      lastPayload = payload;
      renderResult(data);
    } catch (e) {
      err.textContent = e.message || 'Не удалось выполнить расчёт';
      setExportEnabled(false);
    } finally {
      btn.disabled = false;
    }
  }

  function init() {
    var form = qs('#gazobeton-form');
    var box = qs('#openings');
    var addBtn = qs('#add-opening');
    var binderPrice = qs('#binder_price');
    var xlsBtn = qs('#export-xls');
    var pdfBtn = qs('#export-pdf');
    if (xlsBtn) xlsBtn.addEventListener('click', function () { exportResult('xls'); });
    if (pdfBtn) pdfBtn.addEventListener('click', function () { exportResult('pdf'); });

    renderOpenings();
    syncWallMode();
    syncBinderUi();
    bindDecimalFields(form || document);

    qsa('input[name="wall_mode"]').forEach(function (el) {
      el.addEventListener('change', syncWallMode);
    });

    qsa('input[name="binder_mode"]').forEach(function (el) {
      el.addEventListener('change', function () {
        if (binderPrice) delete binderPrice.dataset.touched;
        syncBinderUi();
      });
    });

    ['#width_m', '#length_m'].forEach(function (sel) {
      var el = qs(sel);
      if (!el) return;
      el.addEventListener('input', syncPerimeterFromBox);
      el.addEventListener('change', syncPerimeterFromBox);
    });
    syncPerimeterFromBox();

    if (binderPrice) {
      binderPrice.addEventListener('input', function () {
        binderPrice.dataset.touched = '1';
      });
    }

    if (addBtn) {
      addBtn.addEventListener('click', function () {
        syncOpeningsFromDom();
        openings.push({ w: 1.5, h: 1.4, n: 1 });
        renderOpenings();
      });
    }

    if (box) {
      box.addEventListener('input', function (e) {
        var t = e.target;
        if (!(t instanceof Element)) return;
        var i = t.getAttribute('data-i');
        var k = t.getAttribute('data-k');
        if (i == null || k == null) return;
        var idx = Number(i);
        if (!openings[idx]) return;
        openings[idx][k] = parseNum(t.value);
      });
      box.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof Element)) return;
        var btn = t.closest('[data-del]');
        if (!btn) return;
        e.preventDefault();
        syncOpeningsFromDom();
        var idx = Number(btn.getAttribute('data-del'));
        if (!isFinite(idx)) return;
        openings.splice(idx, 1);
        renderOpenings();
      });
    }

    if (form) form.addEventListener('submit', onSubmit);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
