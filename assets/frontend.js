(function () {
  'use strict';

  var settings = window.sunstreakerPreview || null;
  if (!settings || !settings.productId) return;
  window.__sunstreakerFrontendBooted = window.__sunstreakerFrontendBooted || {};
  if (window.__sunstreakerFrontendBooted[settings.productId]) return;
  window.__sunstreakerFrontendBooted[settings.productId] = true;

  var defaults = settings.defaults || {
    name: { x: 0.22, y: 0.26, w: 0.56, h: 0.12 },
    number: { x: 0.30, y: 0.41, w: 0.40, h: 0.24 },
    logo: { x: 0.18, y: 0.20, w: 0.20, h: 0.20 },
    right_chest: { x: 0.18, y: 0.20, w: 0.34, h: 0.12 }
  };
  var boundaryKeys = Object.keys(defaults);
  var textFields = ['name', 'number'];
  var previewDefaults = settings.previewDefaults || {
    name: 'YOUR NAME',
    number: '26',
    rightChestNameCredentials: 'Name & Credentials',
    rightChestDepartment: 'Department'
  };
  var previewFontStack = settings.fontStack || '"Varsity Block","Freshman","College","Oswald","Arial Black",sans-serif';
  var rightChestFontStack = settings.rightChestFontStack || previewFontStack;
  var constraints = settings.constraints || {};
  var rightChestMaxWidthIn = Math.max(0.01, Number(constraints.rightChestMaxWidthIn || 4));
  var rightChestMinLetterHeightIn = Math.max(0.01, Number(constraints.rightChestMinLetterHeightIn || 0.25));
  var debugMode = debugModeEnabled();
  var useNameNumber = settingEnabled(settings.useNameNumber);
  var useLogos = settingEnabled(settings.useLogos);
  var useRightChestText = settingEnabled(settings.useRightChestText);
  var useFrontBack = settingEnabled(settings.useFrontBack);
  var logoOptions = Array.isArray(settings.logos) ? settings.logos : [];
  var logoMap = {};
  var strings = settings.strings || {};
  var labels = { name: 'Name', number: 'Number', logo: 'Logo', right_chest: 'Right Chest', front: 'Front', back: 'Back' };
  var state = {
    image: null,
    frame: null,
    stage: null,
    controls: null,
    logoDropdown: null,
    primaryButton: null,
    cancelButton: null,
    statusEl: null,
    observer: null,
    resizeObserver: null,
    rafId: 0,
    drag: null,
    editing: false,
    debug: debugMode,
    boundaries: normalizeBoundaries(settings.boundaries || defaults),
    savedBoundaries: normalizeBoundaries(settings.boundaries || defaults),
    els: {}
  };

  logoOptions.forEach(function (logo) {
    var id = Number(logo && logo.id);
    if (!id) return;
    logoMap[String(id)] = logo;
  });

  function clamp(value, min, max) { return Math.max(min, Math.min(max, value)); }
  function settingEnabled(value) {
    var normalized;
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value !== 0;
    if (typeof value === 'string') {
      normalized = value.trim().toLowerCase();
      if (normalized === 'yes' || normalized === 'true' || normalized === '1') return true;
      if (normalized === 'no' || normalized === 'false' || normalized === '0' || normalized === '') return false;
    }
    return !!value;
  }
  function statusText(key, fallback) { return strings[key] || fallback; }
  function roundValue(value) { return Math.round(value * 1000000) / 1000000; }
  function cloneRect(rect) { return { x: rect.x, y: rect.y, w: rect.w, h: rect.h }; }
  function debugModeEnabled() {
    var search = window.location && typeof window.location.search === 'string' ? window.location.search : '';
    if (window.SUNSTREAKER_DEBUG === true) return true;
    if (/(?:\?|&)sunstreaker_debug=1(?:&|$)/.test(search)) return true;
    try {
      return !!(window.localStorage && window.localStorage.getItem('sunstreakerDebug') === '1');
    } catch (error) {
      return false;
    }
  }
  function previewDefaultValue(key, fallback) {
    if (!Object.prototype.hasOwnProperty.call(previewDefaults, key)) return String(fallback);
    if (previewDefaults[key] === null || typeof previewDefaults[key] === 'undefined') return '';
    return String(previewDefaults[key]);
  }
  function fieldInputId(field) { return field === 'logo' ? 'sunstreaker_logo_id' : 'sunstreaker_' + field; }
  function inputFor(field) { return document.getElementById(fieldInputId(field)); }
  function fontInputFor(group) {
    return document.getElementById(group === 'right_chest' ? 'sunstreaker_right_chest_font_choice' : 'sunstreaker_font_choice');
  }
  function rightChestInput(part) {
    if (part === 'name') return document.getElementById('sunstreaker_right_chest_name_credentials');
    if (part === 'department') return document.getElementById('sunstreaker_right_chest_department');
    return null;
  }
  function artUrlInput(field) {
    if (field !== 'front' && field !== 'back') return null;
    return document.getElementById('sunstreaker_' + field + '_art_url');
  }
  function fieldEnabled(field) {
    if (field === 'logo') return useLogos;
    if (field === 'name' || field === 'number') return useNameNumber;
    if (field === 'right_chest') return useRightChestText;
    if (field === 'front' || field === 'back') return useFrontBack;
    return true;
  }
  function isTextField(field) { return textFields.indexOf(field) !== -1; }
  function isRightChestField(field) { return field === 'right_chest'; }
  function selectedFontOption(selectEl) {
    if (!selectEl || !selectEl.options || selectEl.selectedIndex < 0) return null;
    return selectEl.options[selectEl.selectedIndex] || null;
  }
  function optionFontStack(optionEl) {
    return optionEl ? String(optionEl.getAttribute('data-font-stack') || '') : '';
  }
  function groupFontStack(group) {
    var selectEl = fontInputFor(group);
    var optionEl = selectedFontOption(selectEl);
    var stack = optionFontStack(optionEl);
    return stack || (group === 'right_chest' ? rightChestFontStack : previewFontStack);
  }
  function primaryFontFamily(stack) {
    var normalized = String(stack || '').trim();
    var quotedMatch;
    if (!normalized) return '';
    quotedMatch = normalized.match(/"([^"]+)"/);
    if (quotedMatch && quotedMatch[1]) return quotedMatch[1];
    return normalized.split(',')[0].trim().replace(/^['"]|['"]$/g, '');
  }
  function fontFamiliesFromStack(stack) {
    return String(stack || '').split(',').map(function (part) {
      return String(part || '').trim().replace(/^['"]|['"]$/g, '');
    }).filter(function (family) {
      return family !== '';
    });
  }
  function groupFontChoiceKey(group) {
    var selectEl = fontInputFor(group);
    return selectEl && typeof selectEl.value === 'string' ? String(selectEl.value).trim() : '';
  }
  function rightChestFontProfile() {
    var choiceKey = groupFontChoiceKey('right_chest');
    var stack = groupFontStack('right_chest');
    if (choiceKey === 'birds_of_paradise' || /birds of paradise|alex brush|allura/i.test(stack)) {
      return { script: true, width: 1.34, height: 1.42, tracking: 0.000, nameWeight: '400', departmentWeight: '400', paddingX: 0.12, paddingY: 0.16, gap: 0.12 };
    }
    if (choiceKey === 'caveat_brush' || /caveat brush/i.test(stack)) {
      return { script: true, width: 1.28, height: 1.34, tracking: 0.001, nameWeight: '400', departmentWeight: '400', paddingX: 0.10, paddingY: 0.14, gap: 0.10 };
    }
    if (choiceKey === 'ravi_prakash' || /ravi prakash/i.test(stack)) {
      return { script: true, width: 1.28, height: 1.32, tracking: 0.002, nameWeight: '400', departmentWeight: '400', paddingX: 0.10, paddingY: 0.14, gap: 0.10 };
    }
    if (choiceKey === 'baloo' || /baloo/i.test(stack)) {
      return { script: false, width: 1.22, height: 1.18, tracking: 0.002, nameWeight: '600', departmentWeight: '500', paddingX: 0.07, paddingY: 0.09, gap: 0.08 };
    }
    if (choiceKey === 'original_surfer' || /original surfer/i.test(stack)) {
      return { script: false, width: 1.24, height: 1.18, tracking: 0.002, nameWeight: '400', departmentWeight: '400', paddingX: 0.07, paddingY: 0.09, gap: 0.08 };
    }
    if (choiceKey === 'averia_serif_libre' || /averia serif libre/i.test(stack)) {
      return { script: false, width: 1.22, height: 1.24, tracking: 0.002, nameWeight: '700', departmentWeight: '400', paddingX: 0.07, paddingY: 0.09, gap: 0.08 };
    }
    if (choiceKey === 'arial' || /arial|liberation sans|helvetica/i.test(stack)) {
      return { script: false, width: 1.18, height: 1.18, tracking: 0.000, nameWeight: '700', departmentWeight: '400', paddingX: 0.06, paddingY: 0.08, gap: 0.08 };
    }
    return { script: false, width: 1.18, height: 1.18, tracking: 0.001, nameWeight: '600', departmentWeight: '500', paddingX: 0.06, paddingY: 0.08, gap: 0.08 };
  }
  function rightChestUsesScriptFont() {
    return rightChestFontProfile().script;
  }
  function rightChestFitBuffer() {
    var profile = rightChestFontProfile();
    return { width: profile.width, height: profile.height };
  }
  function rightChestMinHeightRatio() {
    return rightChestMinLetterHeightIn / rightChestMaxWidthIn;
  }
  function rightChestMinHeightPx(boxWidthPx) {
    return Math.max(1, boxWidthPx * rightChestMinHeightRatio());
  }
  function textFitBuffer(field) {
    if ((field === 'right_chest_name' || field === 'right_chest_department') && rightChestUsesScriptFont()) {
      return rightChestFitBuffer();
    }
    if (field === 'right_chest_name' || field === 'right_chest_department') {
      return rightChestFitBuffer();
    }
    return { width: 1, height: 1 };
  }
  function loadPreviewFont(group) {
    var fontsApi = document.fonts;
    if (!fontsApi || typeof fontsApi.load !== 'function') return;
    fontFamiliesFromStack(groupFontStack(group)).slice(0, 4).forEach(function (family) {
      family = family.replace(/"/g, '');
      ['400', '600', '700'].forEach(function (weight) {
        fontsApi.load(weight + ' 32px "' + family + '"', 'Ag').then(scheduleRender, function () {});
      });
    });
  }
  function applyFontSelectStyles() {
    Array.prototype.forEach.call(document.querySelectorAll('.sunstreaker-font-select'), function (selectEl) {
      var optionEl = selectedFontOption(selectEl);
      var stack = optionFontStack(optionEl);
      selectEl.style.fontFamily = stack || '';
    });
  }

  function scrubText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function scrubSetSelectByLabel(selectEl, label) {
    var target = scrubText(label);
    var i;
    var option;
    if (!selectEl || !selectEl.options) return false;

    if (target === '') {
      selectEl.value = '';
      return true;
    }

    for (i = 0; i < selectEl.options.length; i += 1) {
      option = selectEl.options[i];
      if (scrubText(option.textContent || option.value) !== target && scrubText(option.value) !== target) continue;
      selectEl.value = option.value;
      return true;
    }

    return false;
  }

  function scrubSelectedLabel(selectEl) {
    var option;
    if (!selectEl || !selectEl.options || selectEl.selectedIndex < 0 || !selectEl.value) return '';
    option = selectEl.options[selectEl.selectedIndex] || null;
    return option && option.textContent ? String(option.textContent).trim() : '';
  }

  function scrubFindNativeSelect(nativeName) {
    var nativeSelects = document.querySelectorAll('form.cart select[name]');
    var i;
    for (i = 0; i < nativeSelects.length; i += 1) {
      if (String(nativeSelects[i].getAttribute('name') || '') === nativeName) {
        return nativeSelects[i];
      }
    }
    return null;
  }

  function scrubDispatchNativeChange(selectEl) {
    if (!selectEl) return;
    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function scrubSyncCustomFromNative(customSelect, nativeSelect) {
    var label = scrubSelectedLabel(nativeSelect);
    if (!scrubSetSelectByLabel(customSelect, label)) customSelect.value = '';
  }

  function scrubSyncNativeFromCustom(customSelect, nativeSelect) {
    var previousValue;
    if (!customSelect || !nativeSelect) return;

    previousValue = String(nativeSelect.value || '');
    if (customSelect.disabled || customSelect.value === '') {
      nativeSelect.value = '';
    } else if (!scrubSetSelectByLabel(nativeSelect, customSelect.value)) {
      nativeSelect.value = '';
    }

    if (String(nativeSelect.value || '') !== previousValue) {
      scrubDispatchNativeChange(nativeSelect);
    }
  }

  function scrubSyncFieldState(fieldEl, enabled) {
    var customSelect = fieldEl ? fieldEl.querySelector('.sunstreaker-scrubs__select') : null;
    var nativeSelect = fieldEl && fieldEl._sunstreakerNativeSelect ? fieldEl._sunstreakerNativeSelect : null;
    if (!customSelect || !nativeSelect) return;

    if (!enabled) {
      if (nativeSelect.value !== '') {
        nativeSelect.value = '';
        scrubDispatchNativeChange(nativeSelect);
      }
      return;
    }

    scrubSyncNativeFromCustom(customSelect, nativeSelect);
  }

  function bindScrubNativeSelects(root) {
    Array.prototype.forEach.call(root.querySelectorAll('.sunstreaker-scrubs__field'), function (fieldEl) {
      var customSelect = fieldEl.querySelector('.sunstreaker-scrubs__select');
      var nativeName = String(fieldEl.getAttribute('data-native-name') || '');
      var nativeSelect;
      var nativeRow;

      if (!customSelect || !nativeName) return;

      nativeSelect = scrubFindNativeSelect(nativeName);
      if (!nativeSelect) return;

      nativeRow = nativeSelect.closest('tr') || nativeSelect.closest('.value') || nativeSelect.closest('.form-row') || null;
      if (nativeRow) nativeRow.classList.add('sunstreaker-scrubs__native-source');

      fieldEl._sunstreakerNativeSelect = nativeSelect;

      customSelect.addEventListener('change', function () {
        scrubSyncNativeFromCustom(customSelect, nativeSelect);
      });
      nativeSelect.addEventListener('change', function () {
        if (customSelect.disabled) return;
        scrubSyncCustomFromNative(customSelect, nativeSelect);
      });

      if (customSelect.value !== '') {
        scrubSyncNativeFromCustom(customSelect, nativeSelect);
      } else {
        scrubSyncCustomFromNative(customSelect, nativeSelect);
      }
    });
  }

  function relocateScrubCard(root) {
    var cartForm = document.querySelector('form.cart');
    var title = document.querySelector('.summary .product_title, .summary .entry-title, .product .summary h1.product_title, .product .summary h1.entry-title');

    if (cartForm) {
      if (!cartForm.id) cartForm.id = 'sunstreaker-cart-form-' + String(settings.productId || 'product');
      Array.prototype.forEach.call(root.querySelectorAll('input[name], select[name], textarea[name]'), function (control) {
        control.setAttribute('form', cartForm.id);
      });
    }

    if (!title || !title.parentNode) return;
    title.parentNode.insertBefore(root, title.nextSibling);
    root.classList.add('is-relocated');
  }

  function syncScrubCard(root) {
    var yesInput = root.querySelector('input[name="sunstreaker_scrubs"][value="yes"]');
    var card = root.querySelector('[data-sunstreaker-scrubs-card]');
    var enabled = !!(yesInput && yesInput.checked);

    root.classList.toggle('is-active', enabled);
    if (card) card.setAttribute('aria-hidden', enabled ? 'false' : 'true');

    Array.prototype.forEach.call(root.querySelectorAll('.sunstreaker-scrubs__select'), function (selectEl) {
      var fieldEl = selectEl.closest('.sunstreaker-scrubs__field');
      selectEl.disabled = !enabled;
      selectEl.required = enabled;
      if (fieldEl) scrubSyncFieldState(fieldEl, enabled);
    });
  }

  function bindScrubFields() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-sunstreaker-scrubs]'), function (root) {
      relocateScrubCard(root);
      bindScrubNativeSelects(root);
      Array.prototype.forEach.call(root.querySelectorAll('input[name="sunstreaker_scrubs"]'), function (input) {
        input.addEventListener('change', function () {
          syncScrubCard(root);
        });
      });
      syncScrubCard(root);
    });
  }

  function cloneBoundaries(boundaries) {
    var cloned = {};
    boundaryKeys.forEach(function (field) {
      cloned[field] = cloneRect(boundaries[field] || defaults[field]);
    });
    return cloned;
  }

  function normalizeRect(rect, fallback) {
    var next = rect && typeof rect === 'object' ? rect : fallback;
    var x = Number(next.x), y = Number(next.y), w = Number(next.w), h = Number(next.h);
    if (!Number.isFinite(x)) x = fallback.x;
    if (!Number.isFinite(y)) y = fallback.y;
    if (!Number.isFinite(w)) w = fallback.w;
    if (!Number.isFinite(h)) h = fallback.h;
    w = clamp(w, 0.05, 1);
    h = clamp(h, 0.05, 1);
    x = clamp(x, 0, 1 - w);
    y = clamp(y, 0, 1 - h);
    return { x: roundValue(x), y: roundValue(y), w: roundValue(w), h: roundValue(h) };
  }

  function normalizeBoundaries(boundaries) {
    var normalized = {};
    boundaryKeys.forEach(function (field) {
      normalized[field] = normalizeRect(boundaries && boundaries[field], defaults[field]);
    });
    return normalized;
  }

  function setStatus(message, isError) {
    if (!state.statusEl) return;
    state.statusEl.textContent = message || '';
    state.statusEl.classList.toggle('is-error', !!isError);
  }

  function clearStatusLater(delayMs) {
    window.setTimeout(function () { setStatus('', false); }, delayMs || 1500);
  }

  function sanitizeName(value) { return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 20); }
  function sanitizeNumber(value) { return String(value || '').replace(/[^0-9]/g, '').slice(0, 2); }
  function sanitizeRightChestText(value, options) {
    var trimEdges = !options || options.trimEdges !== false;

    value = String(value || '').replace(/\s+/g, ' ');
    value = trimEdges ? value.trim() : value.replace(/^\s+/, '');
    return value.slice(0, 60);
  }
  function sanitizeLogoId(value) {
    var id = parseInt(value, 10);
    return Number.isFinite(id) && id > 0 ? id : 0;
  }

  function fieldValue(field) {
    var input = inputFor(field);
    if (!input) return field === 'logo' ? 0 : '';
    if (field === 'number') return sanitizeNumber(input.value);
    if (field === 'name') return sanitizeName(input.value);
    if (field === 'logo') return sanitizeLogoId(input.value);
    return '';
  }

  function rightChestValues() {
    var nameInput = rightChestInput('name');
    var departmentInput = rightChestInput('department');
    return {
      name: sanitizeRightChestText(nameInput ? nameInput.value : ''),
      department: sanitizeRightChestText(departmentInput ? departmentInput.value : '')
    };
  }

  function rightChestPreviewValues() {
    var values = rightChestValues();
    if (!useRightChestText) {
      return { name: '', department: '' };
    }
    return values;
  }

  function previewValue(field) {
    var value = fieldValue(field);
    if (!useNameNumber) return '';
    if (value !== '') return value;
    return field === 'number'
      ? previewDefaultValue('number', '26')
      : previewDefaultValue('name', 'YOUR NAME');
  }

  function selectedLogo() {
    var logoId = fieldValue('logo');
    if (!logoId) return null;
    return logoMap[String(logoId)] || null;
  }

  function logoOptionLabel(logo) {
    if (!logo) return '';
    if (typeof logo.filename === 'string' && logo.filename.trim() !== '') return logo.filename.trim();
    if (typeof logo.title === 'string' && logo.title.trim() !== '') return logo.title.trim();
    return 'Logo';
  }

  function stripFilenameExtension(label) {
    label = String(label || '').trim();
    if (!label) return '';
    return label.replace(/\.[a-z0-9]{1,8}$/i, '');
  }

  function currentLogoTriggerLabel() {
    var input = inputFor('logo');
    var option;
    if (!input || !input.options || input.selectedIndex < 0) return 'Choose a logo';
    option = input.options[input.selectedIndex] || null;
    return option && option.textContent ? String(option.textContent).trim() : 'Choose a logo';
  }

  function closeLogoDropdown() {
    var dropdown = state.logoDropdown;
    if (!dropdown) return;
    dropdown.container.classList.remove('is-open');
    dropdown.trigger.setAttribute('aria-expanded', 'false');
    dropdown.menu.hidden = true;
  }

  function syncLogoDropdown() {
    var dropdown = state.logoDropdown;
    var selectedValue;
    if (!dropdown) return;

    selectedValue = dropdown.native.value || '';
    dropdown.trigger.textContent = currentLogoTriggerLabel();
    dropdown.trigger.title = dropdown.trigger.textContent;

    dropdown.options.forEach(function (optionEl) {
      var isSelected = String(optionEl.dataset.value || '') === selectedValue;
      optionEl.classList.toggle('is-selected', isSelected);
      optionEl.setAttribute('aria-selected', isSelected ? 'true' : 'false');
    });
  }

  function openLogoDropdown() {
    var dropdown = state.logoDropdown;
    var target;
    if (!dropdown) return;

    dropdown.container.classList.add('is-open');
    dropdown.trigger.setAttribute('aria-expanded', 'true');
    dropdown.menu.hidden = false;
    target = dropdown.menu.querySelector('.sunstreaker-logo-select__option.is-selected') || dropdown.options[0] || null;
    if (target) {
      window.requestAnimationFrame(function () {
        target.focus();
      });
    }
  }

  function toggleLogoDropdown() {
    var dropdown = state.logoDropdown;
    if (!dropdown) return;
    if (dropdown.container.classList.contains('is-open')) closeLogoDropdown();
    else openLogoDropdown();
  }

  function buildLogoDropdown() {
    var native;
    var field;
    var container;
    var trigger;
    var menu;
    var placeholderText;

    function appendOption(value, label, logo) {
      var optionEl = document.createElement('button');
      var thumb;
      var thumbFrame;
      var textWrap;
      var primary;
      var secondary;
      var thumbUrl = logo && (logo.preview_url || logo.thumb_url) ? String(logo.preview_url || logo.thumb_url) : '';
      var filename = logoOptionLabel(logo);
      var displayName = stripFilenameExtension(filename) || filename || label;
      var title = logo && typeof logo.title === 'string' ? logo.title.trim() : '';

      optionEl.type = 'button';
      optionEl.className = 'sunstreaker-logo-select__option';
      optionEl.dataset.value = value;
      optionEl.setAttribute('role', 'option');
      optionEl.setAttribute('aria-selected', 'false');
      optionEl.setAttribute('aria-label', title || filename || label);

      if (thumbUrl !== '') {
        thumbFrame = document.createElement('span');
        thumbFrame.className = 'sunstreaker-logo-select__thumb-frame';

        thumb = document.createElement('img');
        thumb.className = 'sunstreaker-logo-select__thumb';
        thumb.src = thumbUrl;
        thumb.alt = title || filename || label;
        thumb.loading = 'lazy';
        thumbFrame.appendChild(thumb);
        optionEl.classList.add('sunstreaker-logo-select__option--thumb-only');
        optionEl.appendChild(thumbFrame);

        textWrap = document.createElement('span');
        textWrap.className = 'sunstreaker-logo-select__text sunstreaker-logo-select__text--under-thumb';

        primary = document.createElement('span');
        primary.className = 'sunstreaker-logo-select__primary';
        primary.textContent = displayName;
        primary.title = displayName;
        textWrap.appendChild(primary);

        optionEl.appendChild(textWrap);
      }

      if (thumbUrl === '') {
        textWrap = document.createElement('span');
        textWrap.className = 'sunstreaker-logo-select__text';

        primary = document.createElement('span');
        primary.className = 'sunstreaker-logo-select__primary';
        primary.textContent = logo ? filename : label;
        textWrap.appendChild(primary);

        if (logo && title !== '' && title !== filename) {
          secondary = document.createElement('span');
          secondary.className = 'sunstreaker-logo-select__secondary';
          secondary.textContent = title;
          textWrap.appendChild(secondary);
        }

        optionEl.appendChild(textWrap);
      }
      optionEl.addEventListener('click', function (event) {
        event.preventDefault();
        native.value = value;
        native.dispatchEvent(new Event('change', { bubbles: true }));
        closeLogoDropdown();
        trigger.focus();
      });
      menu.appendChild(optionEl);
    }

    if (!useLogos || state.logoDropdown) return;

    native = inputFor('logo');
    if (!native || native.disabled) return;

    field = native.closest('.sunstreaker-field');
    if (!field) return;

    placeholderText = native.options && native.options.length ? String(native.options[0].textContent || '').trim() : 'Choose a logo';
    container = document.createElement('div');
    container.className = 'sunstreaker-logo-select';

    trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'sunstreaker-select sunstreaker-logo-select__trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');

    menu = document.createElement('div');
    menu.className = 'sunstreaker-logo-select__menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;

    appendOption('', placeholderText, null);
    logoOptions.forEach(function (logo) {
      var value = logo && logo.id ? String(logo.id) : '';
      if (!value) return;
      appendOption(value, logoOptionLabel(logo), logo);
    });

    container.appendChild(trigger);
    container.appendChild(menu);
    field.appendChild(container);

    native.hidden = true;
    native.setAttribute('aria-hidden', 'true');
    native.tabIndex = -1;

    state.logoDropdown = {
      native: native,
      container: container,
      trigger: trigger,
      menu: menu,
      options: Array.prototype.slice.call(menu.querySelectorAll('.sunstreaker-logo-select__option'))
    };

    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      toggleLogoDropdown();
    });
    trigger.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openLogoDropdown();
      }
    });
    menu.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeLogoDropdown();
        trigger.focus();
      }
    });
    native.addEventListener('change', syncLogoDropdown);
    document.addEventListener('pointerdown', function (event) {
      if (!state.logoDropdown) return;
      if (container.contains(event.target)) return;
      closeLogoDropdown();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeLogoDropdown();
    });

    syncLogoDropdown();
  }

  function letterSpacingRatio(field) {
    if (field === 'right_chest_name' || field === 'right_chest_department') return rightChestFontProfile().tracking;
    return field === 'number' ? 0.025 : 0.03;
  }

  function numberMeasurementValue(value) {
    value = String(value || '').trim();
    return value.length === 1 ? (value + value) : value;
  }

  function measureBoundaryTextRect(valueEl, measurementValue) {
    var clone, rect;

    measurementValue = String(measurementValue || '');
    if (!valueEl || !valueEl.parentElement || measurementValue === '' || measurementValue === valueEl.textContent) {
      return valueEl.getBoundingClientRect();
    }

    clone = valueEl.cloneNode(true);
    clone.textContent = measurementValue;
    clone.style.position = 'absolute';
    clone.style.left = '0';
    clone.style.top = '0';
    clone.style.visibility = 'hidden';
    clone.style.pointerEvents = 'none';
    clone.style.transform = 'scale(1, 1)';
    valueEl.parentElement.appendChild(clone);
    rect = clone.getBoundingClientRect();
    clone.remove();
    return rect;
  }

  function debugRectRelativeToBoundary(boundaryEl, targetEl) {
    var boundaryRect, targetRect;
    if (!boundaryEl || !targetEl) return null;
    boundaryRect = boundaryEl.getBoundingClientRect();
    targetRect = targetEl.getBoundingClientRect();
    if (!boundaryRect.width || !boundaryRect.height || !targetRect.width || !targetRect.height) return null;
    return {
      left: targetRect.left - boundaryRect.left,
      top: targetRect.top - boundaryRect.top,
      right: targetRect.right - boundaryRect.left,
      bottom: targetRect.bottom - boundaryRect.top,
      width: targetRect.width,
      height: targetRect.height
    };
  }

  function debugTextSummary(targetEl) {
    var style, fontSize;
    if (!targetEl) return '';
    style = window.getComputedStyle(targetEl);
    fontSize = parseFloat(style.fontSize || '0');
    return (Math.round(fontSize * 10) / 10) + 'px';
  }

  function updateDebugBox(boundaryEl, boxEl, targetEl, constraintEl) {
    var rect = debugRectRelativeToBoundary(boundaryEl, targetEl);
    var constraintRect = debugRectRelativeToBoundary(boundaryEl, constraintEl || targetEl);
    var overflow = false;

    if (!boxEl) return false;
    if (!rect || !constraintRect) {
      boxEl.hidden = true;
      boxEl.classList.remove('is-overflowing');
      return false;
    }

    overflow = rect.left < (constraintRect.left - 1)
      || rect.top < (constraintRect.top - 1)
      || rect.right > (constraintRect.right + 1)
      || rect.bottom > (constraintRect.bottom + 1);

    boxEl.hidden = false;
    boxEl.style.left = rect.left + 'px';
    boxEl.style.top = rect.top + 'px';
    boxEl.style.width = rect.width + 'px';
    boxEl.style.height = rect.height + 'px';
    boxEl.classList.toggle('is-overflowing', overflow);
    return overflow;
  }

  function renderDebug(field, el) {
    var debug = el && el._debug ? el._debug : null;
    var lines = [];
    var overflow = false;
    var valueEl, contentEl, values, nameRow, departmentRow, nameValue, departmentValue, imageEl, fontLabel;

    if (!debug) return;
    if (!state.debug || !fieldEnabled(field)) {
      debug.wrapper.hidden = true;
      return;
    }

    debug.wrapper.hidden = false;
    debug.boxes[0].hidden = true;
    debug.boxes[1].hidden = true;

    if (field === 'name' || field === 'number') {
      valueEl = el.querySelector('.sunstreaker-boundary__value');
      contentEl = el.querySelector('.sunstreaker-boundary__text');
      overflow = updateDebugBox(el, debug.boxes[0], valueEl, contentEl);
      fontLabel = groupFontChoiceKey('name_number') || primaryFontFamily(groupFontStack('name_number'));
      lines.push((fontLabel || field) + ' | ' + debugTextSummary(valueEl));
      if (valueEl) lines.push(Math.round(valueEl.getBoundingClientRect().width) + 'x' + Math.round(valueEl.getBoundingClientRect().height) + (overflow ? ' overflow' : ' fit'));
    } else if (field === 'right_chest') {
      values = rightChestPreviewValues();
      nameRow = el.querySelector('.sunstreaker-boundary__right-chest-line--name');
      departmentRow = el.querySelector('.sunstreaker-boundary__right-chest-line--department');
      nameValue = nameRow ? nameRow.querySelector('.sunstreaker-boundary__right-chest-value') : null;
      departmentValue = departmentRow ? departmentRow.querySelector('.sunstreaker-boundary__right-chest-value') : null;
      overflow = updateDebugBox(el, debug.boxes[0], nameValue, nameRow) || overflow;
      overflow = updateDebugBox(el, debug.boxes[1], departmentValue, departmentRow) || overflow;
      fontLabel = groupFontChoiceKey('right_chest') || primaryFontFamily(groupFontStack('right_chest'));
      lines.push((fontLabel || 'right_chest') + ' | ' + debugTextSummary(nameValue || departmentValue));
      if (values.name !== '' && nameValue) lines.push('N ' + Math.round(nameValue.getBoundingClientRect().width) + 'x' + Math.round(nameValue.getBoundingClientRect().height) + ' min ' + (nameValue.dataset.sunstreakerMinHeightPx || '?') + 'px');
      if (values.department !== '' && departmentValue) lines.push('D ' + Math.round(departmentValue.getBoundingClientRect().width) + 'x' + Math.round(departmentValue.getBoundingClientRect().height) + ' min ' + (departmentValue.dataset.sunstreakerMinHeightPx || '?') + 'px');
      if (overflow) lines.push('overflow');
    } else if (field === 'logo') {
      imageEl = el.querySelector('.sunstreaker-boundary__image');
      updateDebugBox(el, debug.boxes[0], imageEl, el.querySelector('.sunstreaker-boundary__media'));
      lines.push('logo');
    }

    debug.info.textContent = lines.join(' | ');
  }

  function fitBoundaryText(valueEl, field, maxWidth, maxHeight) {
    var safeWidth = Math.max(1, maxWidth);
    var safeHeight = Math.max(1, maxHeight);
    var baseFontSize = 100;
    var tracking = letterSpacingRatio(field) * baseFontSize;
    var transformOrigin = 'center center';
    var rect, fittedRect, scaleX, scaleY, attempts, measurementValue, fitBuffer, isRightChestField, widthRatio, heightRatio, uniformScale, minHeightPx, overflow;

    if (field === 'number') {
      baseFontSize = 110;
      tracking = letterSpacingRatio(field) * baseFontSize;
    } else if (field === 'right_chest_name') {
      baseFontSize = 88;
      tracking = letterSpacingRatio(field) * baseFontSize;
      transformOrigin = 'center center';
    } else if (field === 'right_chest_department') {
      baseFontSize = 78;
      tracking = letterSpacingRatio(field) * baseFontSize;
      transformOrigin = 'center center';
    }

    valueEl.style.fontSize = baseFontSize + 'px';
    valueEl.style.letterSpacing = tracking + 'px';
    valueEl.style.transformOrigin = transformOrigin;
    valueEl.style.transform = '';

    measurementValue = field === 'number'
      ? numberMeasurementValue(valueEl.textContent)
      : valueEl.textContent;
    rect = measureBoundaryTextRect(valueEl, measurementValue);
    if (!rect.width || !rect.height) return;
    fitBuffer = textFitBuffer(field);
    isRightChestField = field === 'right_chest_name' || field === 'right_chest_department';

    if (isRightChestField) {
      minHeightPx = rightChestMinHeightPx(safeWidth);
      for (attempts = 0; attempts < 8; attempts += 1) {
        widthRatio = (safeWidth * 0.995) / Math.max(1, rect.width * fitBuffer.width);
        heightRatio = (safeHeight * 0.995) / Math.max(1, rect.height * fitBuffer.height);
        uniformScale = Math.min(widthRatio, heightRatio);
        if (!Number.isFinite(uniformScale) || uniformScale <= 0) break;
        if (Math.abs(uniformScale - 1) < 0.01) break;
        baseFontSize = Math.max(1, baseFontSize * uniformScale);
        tracking = letterSpacingRatio(field) * baseFontSize;
        valueEl.style.fontSize = baseFontSize + 'px';
        valueEl.style.letterSpacing = tracking + 'px';
        valueEl.style.transform = '';
        rect = measureBoundaryTextRect(valueEl, measurementValue);
        if (!rect.width || !rect.height) break;
        if ((rect.width * fitBuffer.width) <= safeWidth && (rect.height * fitBuffer.height) <= safeHeight) break;
      }

      if (rect.height && rect.height < minHeightPx) {
        uniformScale = minHeightPx / Math.max(1, rect.height);
        baseFontSize = Math.max(1, baseFontSize * uniformScale);
        tracking = letterSpacingRatio(field) * baseFontSize;
        valueEl.style.fontSize = baseFontSize + 'px';
        valueEl.style.letterSpacing = tracking + 'px';
        rect = measureBoundaryTextRect(valueEl, measurementValue);
      }

      overflow = !!(rect.width && rect.height && ((rect.width * fitBuffer.width) > safeWidth || (rect.height * fitBuffer.height) > safeHeight));
      valueEl.dataset.sunstreakerMinHeightPx = String(Math.round(minHeightPx * 10) / 10);
      valueEl.dataset.sunstreakerRenderedHeightPx = rect.height ? String(Math.round(rect.height * 10) / 10) : '';
      valueEl.dataset.sunstreakerOverflow = overflow ? '1' : '0';
      valueEl.style.transform = '';
      return;
    }

    scaleX = (safeWidth * 0.995) / (rect.width * fitBuffer.width);
    scaleY = (safeHeight * 0.995) / (rect.height * fitBuffer.height);

    if (!Number.isFinite(scaleX) || scaleX <= 0) scaleX = 1;
    if (!Number.isFinite(scaleY) || scaleY <= 0) scaleY = 1;

    valueEl.style.transform = 'scale(' + scaleX + ', ' + scaleY + ')';

    for (attempts = 0; attempts < 3; attempts += 1) {
      fittedRect = valueEl.getBoundingClientRect();
      if (!fittedRect.width || !fittedRect.height) break;
      if (fittedRect.width <= safeWidth && fittedRect.height <= safeHeight) break;

      if (fittedRect.width > safeWidth) {
        scaleX *= (safeWidth * 0.995) / fittedRect.width;
      }
      if (fittedRect.height > safeHeight) {
        scaleY *= (safeHeight * 0.995) / fittedRect.height;
      }

      valueEl.style.transform = 'scale(' + scaleX + ', ' + scaleY + ')';
    }
  }

  function activeImage() {
    var image = document.querySelector('.woocommerce-product-gallery__image.flex-active-slide img');
    var images, i, rect;
    if (image) return image;
    images = document.querySelectorAll('.woocommerce-product-gallery__image img');
    for (i = 0; i < images.length; i += 1) {
      rect = images[i].getBoundingClientRect();
      if (rect.width > 24 && rect.height > 24) return images[i];
    }
    return images.length ? images[0] : null;
  }

  function syncPreviewImageInputs() {
    var image = state.image || activeImage();
    var urlInput = document.getElementById('sunstreaker_preview_image_url');
    var widthInput = document.getElementById('sunstreaker_preview_image_width');
    var heightInput = document.getElementById('sunstreaker_preview_image_height');
    var imageUrl, imageWidth, imageHeight;

    if (!image || (!urlInput && !widthInput && !heightInput)) return;

    imageUrl = image.getAttribute('data-large_image') || image.currentSrc || image.getAttribute('src') || '';
    imageWidth = parseInt(image.getAttribute('data-large_image_width'), 10) || image.naturalWidth || image.width || 0;
    imageHeight = parseInt(image.getAttribute('data-large_image_height'), 10) || image.naturalHeight || image.height || 0;

    if (urlInput) urlInput.value = imageUrl;
    if (widthInput) widthInput.value = imageWidth > 0 ? String(imageWidth) : '';
    if (heightInput) heightInput.value = imageHeight > 0 ? String(imageHeight) : '';
  }

  function createBoundary(field) {
    var boundary = document.createElement('div');
    var label = document.createElement('div');
    var content;
    var handle = document.createElement('button');
    var line;
    var debug;

    boundary.className = 'sunstreaker-boundary';
    boundary.dataset.field = field;
    if (field === 'logo') boundary.classList.add('sunstreaker-boundary--logo');
    if (field === 'right_chest') boundary.classList.add('sunstreaker-boundary--right-chest');

    label.className = 'sunstreaker-boundary__label';
    label.textContent = labels[field] || field;
    boundary.appendChild(label);

    if (isTextField(field)) {
      content = document.createElement('div');
      content.className = 'sunstreaker-boundary__text';
      content.appendChild(document.createElement('span')).className = 'sunstreaker-boundary__value';
    } else if (isRightChestField(field)) {
      content = document.createElement('div');
      content.className = 'sunstreaker-boundary__right-chest';

      line = document.createElement('div');
      line.className = 'sunstreaker-boundary__right-chest-line sunstreaker-boundary__right-chest-line--name';
      line.appendChild(document.createElement('span')).className = 'sunstreaker-boundary__right-chest-value';
      content.appendChild(line);

      line = document.createElement('div');
      line.className = 'sunstreaker-boundary__right-chest-line sunstreaker-boundary__right-chest-line--department';
      line.appendChild(document.createElement('span')).className = 'sunstreaker-boundary__right-chest-value';
      content.appendChild(line);
    } else {
      content = document.createElement('div');
      content.className = 'sunstreaker-boundary__media';
      content.appendChild(document.createElement('img')).className = 'sunstreaker-boundary__image';
    }

    handle.type = 'button';
    handle.className = 'sunstreaker-boundary__handle';
    handle.setAttribute('aria-label', (labels[field] || field) + ' resize handle');

    if (state.debug) {
      debug = document.createElement('div');
      debug.className = 'sunstreaker-boundary__debug';
      debug.hidden = true;
      debug.appendChild(document.createElement('div')).className = 'sunstreaker-boundary__debug-box sunstreaker-boundary__debug-box--primary';
      debug.appendChild(document.createElement('div')).className = 'sunstreaker-boundary__debug-box sunstreaker-boundary__debug-box--secondary';
      debug.appendChild(document.createElement('div')).className = 'sunstreaker-boundary__debug-info';
      boundary._debug = {
        wrapper: debug,
        boxes: debug.querySelectorAll('.sunstreaker-boundary__debug-box'),
        info: debug.querySelector('.sunstreaker-boundary__debug-info')
      };
    }

    boundary.appendChild(content);
    if (debug) boundary.appendChild(debug);
    boundary.appendChild(handle);
    state.els[field] = boundary;
    return boundary;
  }

  function ensureStage() {
    var image = activeImage();
    var frame;

    if (!image) return false;
    frame = image.closest('.woocommerce-product-gallery__image') || image.parentElement;
    if (!frame) return false;

    if (state.frame !== frame && state.stage && state.stage.parentNode) {
      state.stage.parentNode.removeChild(state.stage);
    }

    state.image = image;
    state.frame = frame;
    frame.classList.add('sunstreaker-preview-target');
    if (window.getComputedStyle(frame).position === 'static') frame.style.position = 'relative';

    if (!state.stage) {
      state.stage = document.createElement('div');
      state.stage.className = 'sunstreaker-preview-layer';
      state.stage.style.setProperty('--sunstreaker-preview-ink', settings.inkColor || '#ffffff');
      state.stage.style.setProperty('--sunstreaker-preview-font', previewFontStack);
      state.stage.style.setProperty('--sunstreaker-preview-right-chest-font', rightChestFontStack);
      boundaryKeys.forEach(function (field) {
        state.stage.appendChild(createBoundary(field));
      });
      state.stage.addEventListener('pointerdown', onPointerDown);
      state.stage.addEventListener('dragstart', function (event) { event.preventDefault(); });
    }

    if (state.stage.parentNode !== frame) frame.appendChild(state.stage);

    if (window.ResizeObserver) {
      if (!state.resizeObserver) state.resizeObserver = new ResizeObserver(scheduleRender);
      state.resizeObserver.disconnect();
      state.resizeObserver.observe(frame);
      state.resizeObserver.observe(image);
    }

    return true;
  }

  function renderTextBoundary(el, field) {
    var value = previewValue(field);
    var valueEl = el.querySelector('.sunstreaker-boundary__value');
    var paddingX, paddingY;

    el.classList.toggle('is-empty', value === '');
    if (!valueEl) return;
    if (valueEl.parentElement) valueEl.parentElement.style.fontFamily = groupFontStack('name_number');
    valueEl.textContent = value;

    if (value === '') {
      valueEl.style.fontSize = '';
      valueEl.style.letterSpacing = '';
      valueEl.style.transform = '';
      return;
    }

    paddingX = Math.max(2, el.clientWidth * 0.01);
    paddingY = Math.max(2, el.clientHeight * 0.01);
    fitBoundaryText(valueEl, field, el.clientWidth - (paddingX * 2), el.clientHeight - (paddingY * 2));
  }

  function renderRightChestBoundary(el) {
    var values = rightChestPreviewValues();
    var rows = el.querySelectorAll('.sunstreaker-boundary__right-chest-line');
    var contentEl = el.querySelector('.sunstreaker-boundary__right-chest');
    var nameRow = rows[0] || null;
    var departmentRow = rows[1] || null;
    var nameValue = nameRow ? nameRow.querySelector('.sunstreaker-boundary__right-chest-value') : null;
    var departmentValue = departmentRow ? departmentRow.querySelector('.sunstreaker-boundary__right-chest-value') : null;
    var hasName = values.name !== '';
    var hasDepartment = values.department !== '';
    var profile = rightChestFontProfile();
    var usesScriptFont = profile.script;
    var paddingX = Math.max(4, el.clientWidth * profile.paddingX);
    var paddingY = Math.max(4, el.clientHeight * profile.paddingY);
    var usableWidth = Math.max(1, el.clientWidth - (paddingX * 2));
    var usableHeight = Math.max(1, el.clientHeight - (paddingY * 2));
    var gap = hasName && hasDepartment ? Math.max(2, usableHeight * profile.gap) : 0;
    var remainingHeight = Math.max(1, usableHeight - gap);
    var nameHeight = hasName && hasDepartment ? remainingHeight * 0.56 : remainingHeight;
    var departmentHeight = hasName && hasDepartment ? remainingHeight - nameHeight : remainingHeight;

    el.classList.toggle('is-empty', !hasName && !hasDepartment);
    if (!nameRow || !departmentRow || !nameValue || !departmentValue) return;
    if (contentEl) contentEl.style.fontFamily = groupFontStack('right_chest');
    if (contentEl) contentEl.classList.toggle('is-script-font', usesScriptFont);
    nameRow.style.fontWeight = profile.nameWeight;
    departmentRow.style.fontWeight = profile.departmentWeight;

    nameValue.textContent = values.name;
    departmentValue.textContent = values.department;

    nameRow.hidden = !hasName;
    departmentRow.hidden = !hasDepartment;
    nameRow.style.height = hasName ? Math.max(1, nameHeight) + 'px' : '';
    departmentRow.style.height = hasDepartment ? Math.max(1, departmentHeight) + 'px' : '';
    nameRow.style.marginBottom = hasName && hasDepartment ? gap + 'px' : '0';

    if (hasName) {
      fitBoundaryText(nameValue, 'right_chest_name', usableWidth, Math.max(1, nameHeight));
    } else {
      nameValue.style.fontSize = '';
      nameValue.style.letterSpacing = '';
      nameValue.style.transform = '';
      nameValue.dataset.sunstreakerMinHeightPx = '';
      nameValue.dataset.sunstreakerRenderedHeightPx = '';
      nameValue.dataset.sunstreakerOverflow = '';
    }

    if (hasDepartment) {
      fitBoundaryText(departmentValue, 'right_chest_department', usableWidth, Math.max(1, departmentHeight));
    } else {
      departmentValue.style.fontSize = '';
      departmentValue.style.letterSpacing = '';
      departmentValue.style.transform = '';
      departmentValue.dataset.sunstreakerMinHeightPx = '';
      departmentValue.dataset.sunstreakerRenderedHeightPx = '';
      departmentValue.dataset.sunstreakerOverflow = '';
    }
  }

  function mediaBoundarySource(field) {
    var logo;

    if (field === 'logo') {
      logo = selectedLogo();
      return {
        src: logo && logo.preview_url ? String(logo.preview_url) : '',
        alt: logo && (logo.alt || logo.title) ? String(logo.alt || logo.title) : 'Logo'
      };
    }

    return { src: '', alt: '' };
  }

  function renderMediaBoundary(el, field) {
    var media = mediaBoundarySource(field);
    var imageEl = el.querySelector('.sunstreaker-boundary__image');
    var src = media.src;

    el.classList.toggle('is-empty', src === '');
    if (!imageEl) return;

    if (src === '') {
      imageEl.removeAttribute('src');
      imageEl.removeAttribute('alt');
      imageEl.style.display = 'none';
      return;
    }

    imageEl.decoding = 'async';
    imageEl.loading = 'eager';
    imageEl.alt = media.alt;
    imageEl.src = src;
    imageEl.style.display = 'block';
  }

  function renderBoundary(field) {
    var el = state.els[field];
    var rect = state.boundaries[field];

    if (!el || !rect) return;

    el.classList.toggle('is-hidden', !fieldEnabled(field));
    if (!fieldEnabled(field)) return;

    el.style.left = (rect.x * 100) + '%';
    el.style.top = (rect.y * 100) + '%';
    el.style.width = (rect.w * 100) + '%';
    el.style.height = (rect.h * 100) + '%';

    if (isTextField(field)) renderTextBoundary(el, field);
    else if (isRightChestField(field)) renderRightChestBoundary(el);
    else renderMediaBoundary(el, field);

    renderDebug(field, el);
  }

  function updateButtons() {
    if (!state.primaryButton || !state.cancelButton) return;
    state.primaryButton.textContent = state.editing ? statusText('save', 'Save Boundaries') : statusText('adjust', 'Adjust Boundaries');
    state.cancelButton.hidden = !state.editing;
  }

  function render() {
    ensureControls();
    if (!ensureStage()) {
      if (state.editing) setStatus(statusText('noImage', 'No product image found.'), true);
      return;
    }
    syncPreviewImageInputs();
    state.stage.classList.toggle('is-editing', state.editing);
    state.stage.classList.toggle('is-debug', !!state.debug);
    boundaryKeys.forEach(renderBoundary);
    updateButtons();
  }

  function scheduleRender() {
    if (state.rafId) return;
    state.rafId = window.requestAnimationFrame(function () {
      state.rafId = 0;
      render();
    });
  }

  function ensureControls() {
    var gallery, holder, primary, cancel, status, existing;

    if (!settings.canEdit || state.controls) return;
    gallery = document.querySelector('.woocommerce-product-gallery');
    if (!gallery || !gallery.parentNode) return;

    existing = document.querySelector('.sunstreaker-boundary-controls[data-sunstreaker-product-id="' + String(settings.productId || '') + '"]');
    if (existing) {
      state.controls = existing;
      state.primaryButton = existing.querySelector('.sunstreaker-boundary-controls__primary');
      state.cancelButton = existing.querySelector('.sunstreaker-boundary-controls__cancel');
      state.statusEl = existing.querySelector('.sunstreaker-boundary-controls__status');
      return;
    }

    holder = document.createElement('div');
    holder.className = 'sunstreaker-boundary-controls';
    holder.dataset.sunstreakerProductId = String(settings.productId || '');

    primary = document.createElement('button');
    primary.type = 'button';
    primary.className = 'button alt sunstreaker-boundary-controls__primary';
    primary.textContent = statusText('adjust', 'Adjust Boundaries');
    primary.addEventListener('click', onPrimaryClick);

    cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button sunstreaker-boundary-controls__cancel';
    cancel.textContent = statusText('cancel', 'Cancel');
    cancel.hidden = true;
    cancel.addEventListener('click', onCancelClick);

    status = document.createElement('div');
    status.className = 'sunstreaker-boundary-controls__status';

    holder.appendChild(primary);
    holder.appendChild(cancel);
    holder.appendChild(status);
    gallery.parentNode.insertBefore(holder, gallery.nextSibling);
    state.controls = holder;
    state.primaryButton = primary;
    state.cancelButton = cancel;
    state.statusEl = status;
  }

  function ajaxBody(action, extra) {
    var body = new FormData();
    body.append('action', action);
    body.append('_ajax_nonce', settings.nonce || '');
    body.append('product_id', String(settings.productId || ''));
    Object.keys(extra || {}).forEach(function (key) {
      body.append(key, extra[key]);
    });
    return body;
  }

  function parseResponse(response) {
    return response.json().then(function (payload) {
      if (!response.ok || !payload || !payload.success || !payload.data) {
        var message = payload && payload.data && payload.data.message ? payload.data.message : ('HTTP ' + response.status);
        throw new Error(message);
      }
      return payload.data;
    });
  }

  function fetchBoundaries() {
    return fetch(settings.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: ajaxBody('sunstreaker_get_boundaries')
    }).then(parseResponse).then(function (data) {
      return normalizeBoundaries(data.boundaries || defaults);
    });
  }

  function saveBoundaries() {
    var payload = {};
    boundaryKeys.forEach(function (field) {
      payload[field] = JSON.stringify(state.boundaries[field]);
    });

    return fetch(settings.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: ajaxBody('sunstreaker_save_boundaries', payload)
    }).then(parseResponse).then(function (data) {
      return normalizeBoundaries(data.boundaries || state.boundaries);
    });
  }

  function onPrimaryClick() {
    if (!state.editing) {
      ensureStage();
      if (!state.stage) {
        setStatus(statusText('noImage', 'No product image found.'), true);
        return;
      }
      setStatus(statusText('loading', 'Loading saved boundaries…'), false);
      fetchBoundaries().then(function (boundaries) {
        state.boundaries = cloneBoundaries(boundaries);
        state.savedBoundaries = cloneBoundaries(boundaries);
        state.editing = true;
        setStatus('', false);
        scheduleRender();
      }).catch(function (error) {
        state.boundaries = cloneBoundaries(state.savedBoundaries);
        state.editing = true;
        setStatus(error && error.message ? error.message : statusText('loadError', 'Could not load boundaries.'), true);
        scheduleRender();
      });
      return;
    }

    setStatus(statusText('saving', 'Saving…'), false);
    saveBoundaries().then(function (boundaries) {
      state.boundaries = cloneBoundaries(boundaries);
      state.savedBoundaries = cloneBoundaries(boundaries);
      settings.boundaries = cloneBoundaries(boundaries);
      state.editing = false;
      scheduleRender();
      setStatus(statusText('saved', 'Saved'), false);
      clearStatusLater(1600);
    }).catch(function (error) {
      setStatus(error && error.message ? error.message : statusText('saveError', 'Could not save boundaries.'), true);
    });
  }

  function onCancelClick() {
    state.boundaries = cloneBoundaries(state.savedBoundaries);
    state.drag = null;
    state.editing = false;
    setStatus('', false);
    scheduleRender();
  }

  function onPointerDown(event) {
    var boundary, field, stageRect;
    if (!state.editing) return;

    boundary = event.target.closest('.sunstreaker-boundary');
    if (!boundary || !state.stage || !state.stage.contains(boundary)) return;
    if (typeof event.button === 'number' && event.button !== 0) return;

    field = boundary.dataset.field;
    stageRect = state.stage.getBoundingClientRect();
    if (!field || !stageRect.width || !stageRect.height || !fieldEnabled(field)) return;

    state.drag = {
      pointerId: event.pointerId,
      field: field,
      mode: event.target.closest('.sunstreaker-boundary__handle') ? 'resize' : 'move',
      startX: event.clientX,
      startY: event.clientY,
      stageWidth: stageRect.width,
      stageHeight: stageRect.height,
      origin: cloneRect(state.boundaries[field]),
      element: boundary
    };

    if (boundary.setPointerCapture) boundary.setPointerCapture(event.pointerId);
    event.preventDefault();
  }

  function onPointerMove(event) {
    var dx, dy, next;
    if (!state.drag) return;

    dx = (event.clientX - state.drag.startX) / state.drag.stageWidth;
    dy = (event.clientY - state.drag.startY) / state.drag.stageHeight;
    next = cloneRect(state.drag.origin);

    if (state.drag.mode === 'move') {
      next.x = clamp(state.drag.origin.x + dx, 0, 1 - next.w);
      next.y = clamp(state.drag.origin.y + dy, 0, 1 - next.h);
    } else {
      next.w = clamp(state.drag.origin.w + dx, 0.05, 1 - next.x);
      next.h = clamp(state.drag.origin.h + dy, 0.05, 1 - next.y);
    }

    state.boundaries[state.drag.field] = normalizeRect(next, defaults[state.drag.field]);
    scheduleRender();
    event.preventDefault();
  }

  function endPointer(event) {
    var element;
    if (!state.drag) return;
    element = state.drag.element;
    if (element && typeof element.releasePointerCapture === 'function' && event && event.pointerId === state.drag.pointerId) {
      try { element.releasePointerCapture(event.pointerId); } catch (error) {}
    }
    state.drag = null;
  }

  function bindInputs() {
    bindScrubFields();
    buildLogoDropdown();

    ['name', 'number'].forEach(function (field) {
      var input = inputFor(field);
      if (!input) return;
      input.addEventListener('input', function () {
        var cleaned = field === 'number' ? sanitizeNumber(input.value) : sanitizeName(input.value);
        if (input.value !== cleaned) input.value = cleaned;
        scheduleRender();
      });
    });

    if (useRightChestText) {
      ['name', 'department'].forEach(function (part) {
        var input = rightChestInput(part);
        if (!input) return;
        input.addEventListener('input', function () {
          var cleaned = sanitizeRightChestText(input.value, { trimEdges: false });
          if (input.value !== cleaned) input.value = cleaned;
          scheduleRender();
        });
      });
    }

    ['name_number', 'right_chest'].forEach(function (group) {
      var input = fontInputFor(group);
      if (!input) return;
      input.addEventListener('change', function () {
        applyFontSelectStyles();
        scheduleRender();
        loadPreviewFont(group);
      });
    });

    if (useLogos) {
      var logoInput = inputFor('logo');
      if (logoInput) {
        logoInput.addEventListener('change', function () {
          syncLogoDropdown();
          scheduleRender();
        });
      }
    }

    if (useFrontBack) {
      ['front', 'back'].forEach(function (field) {
        var input = artUrlInput(field);
        if (!input) return;
        input.addEventListener('input', scheduleRender);
        input.addEventListener('change', scheduleRender);
      });
    }
  }

  function bindObservers() {
    var gallery = document.querySelector('.woocommerce-product-gallery');
    if (!gallery || state.observer) return;

    if (window.MutationObserver) {
      state.observer = new MutationObserver(function (records) {
        var i, target;
        for (i = 0; i < records.length; i += 1) {
          target = records[i].target;
          if (state.stage && target && (target === state.stage || state.stage.contains(target))) continue;
          scheduleRender();
          break;
        }
      });
      state.observer.observe(gallery, {
        attributes: true,
        childList: true,
        subtree: true,
        attributeFilter: ['class', 'style', 'src', 'srcset']
      });
    }

    if (window.jQuery) {
      window.jQuery(document.body).on('found_variation reset_image', function () {
        window.setTimeout(scheduleRender, 50);
        window.setTimeout(scheduleRender, 250);
      });
      window.jQuery(document).on('click', '.flex-control-thumbs img, .woocommerce-product-gallery__thumbnail img', function () {
        window.setTimeout(scheduleRender, 50);
        window.setTimeout(scheduleRender, 250);
      });
    }
  }

  function boot() {
    bindInputs();
    bindObservers();
    applyFontSelectStyles();
    loadPreviewFont('name_number');
    loadPreviewFont('right_chest');
    document.addEventListener('pointermove', onPointerMove, { passive: false });
    document.addEventListener('pointerup', endPointer);
    document.addEventListener('pointercancel', endPointer);
    window.addEventListener('resize', scheduleRender);
    window.addEventListener('load', scheduleRender);
    ensureControls();
    scheduleRender();
    window.setTimeout(scheduleRender, 150);
    window.setTimeout(scheduleRender, 600);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
