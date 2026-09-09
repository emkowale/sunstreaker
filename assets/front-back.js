(function () {
  'use strict';

  var settings = window.sunstreakerPreview || null;
  if (!settings || !settings.productId || !settingEnabled(settings.useFrontBack)) return;
  window.__sunstreakerFrontBackBooted = window.__sunstreakerFrontBackBooted || {};
  if (window.__sunstreakerFrontBackBooted[settings.productId]) return;
  window.__sunstreakerFrontBackBooted[settings.productId] = true;

  var defaultBoundaries = {
    front: { x: 0.20, y: 0.16, w: 0.24, h: 0.24 },
    back: { x: 0.27, y: 0.22, w: 0.46, h: 0.52 }
  };
  var fields = ['front', 'back'];
  var fieldLabels = { front: 'Front', back: 'Back' };
  var state = {
    frame: null,
    image: null,
    stage: null,
    frameUiBound: null,
    els: {},
    arts: {
      front: freshArtState(),
      back: freshArtState()
    },
    drag: null,
    rafId: 0,
    resizeObserver: null,
    observer: null,
    pendingUploads: 0,
    uiVisible: false,
    hideUiTimer: 0,
    debugLast: {}
  };

  function freshArtState() {
    return {
      url: '',
      natural: { w: 0, h: 0 },
      transform: null,
      resetTransform: false,
      loading: false,
      statusMessage: '',
      statusError: false,
      renderedStatus: null
    };
  }

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
  function statusText(key, fallback) { return (settings.strings && settings.strings[key]) || fallback; }
  function debugEnabled() {
    var params;
    try {
      params = new URLSearchParams(window.location.search || '');
      if (params.get('sunstreaker_fb_debug') === '1') return true;
      return window.localStorage && window.localStorage.getItem('sunstreaker_fb_debug') === '1';
    } catch (error) {
      return false;
    }
  }
  function rectSummary(el) {
    var rect;
    if (!el || typeof el.getBoundingClientRect !== 'function') return null;
    rect = el.getBoundingClientRect();
    return {
      left: Math.round(rect.left * 100) / 100,
      top: Math.round(rect.top * 100) / 100,
      width: Math.round(rect.width * 100) / 100,
      height: Math.round(rect.height * 100) / 100
    };
  }
  function debugSnapshot(field, reason) {
    var els = state.els[field];
    var input = artTransformInput(field);
    var status = artStatus(field);
    var statusThumb = status ? status.querySelector('.sunstreaker-art-upload__status-thumb') : null;
    var art = state.arts[field];
    if (!els || !art) return null;
    return {
      reason: reason || '',
      field: field,
      natural: art.natural,
      transform: art.transform,
      inputTransform: input ? input.value : '',
      status: {
        hidden: status ? status.hidden : null,
        className: status ? status.className : '',
        thumbHidden: statusThumb ? statusThumb.hidden : null,
        thumbSrc: statusThumb ? statusThumb.getAttribute('src') || '' : ''
      },
      boundary: rectSummary(els.boundary),
      art: rectSummary(els.art),
      image: rectSummary(els.image),
      handle: rectSummary(els.handle),
      boundaryStyles: {
        left: els.boundary.style.left,
        top: els.boundary.style.top,
        width: els.boundary.style.width,
        height: els.boundary.style.height
      },
      artStyles: {
        left: els.art.style.left,
        top: els.art.style.top,
        width: els.art.style.width,
        height: els.art.style.height
      }
    };
  }
  function debugLog(field, reason, force) {
    var key, now, snapshot;
    if (!debugEnabled()) return;
    key = field + ':' + reason;
    now = Date.now();
    if (!force && state.debugLast[key] && now - state.debugLast[key] < 1000) return;
    state.debugLast[key] = now;
    snapshot = debugSnapshot(field, reason);
    if (snapshot && window.console && typeof window.console.log === 'function') {
      window.console.log('[sunstreaker-front-back]', snapshot);
    }
  }
  function statusDebugSnapshot(field, reason) {
    var status = artStatus(field);
    var thumb = status ? status.querySelector('.sunstreaker-art-upload__status-thumb') : null;
    var text = artStatusText(field);
    return {
      reason: reason || '',
      field: field,
      statusExists: !!status,
      statusHidden: status ? status.hidden : null,
      statusClassName: status ? status.className : '',
      statusText: text ? text.textContent : '',
      thumbExists: !!thumb,
      thumbHidden: thumb ? thumb.hidden : null,
      thumbSrc: thumb ? thumb.getAttribute('src') || '' : '',
      thumbCurrentSrc: thumb ? thumb.currentSrc || '' : '',
      thumbComplete: thumb ? thumb.complete : null,
      thumbNaturalWidth: thumb ? thumb.naturalWidth : null,
      thumbNaturalHeight: thumb ? thumb.naturalHeight : null,
      thumbRect: rectSummary(thumb),
      computed: thumb && window.getComputedStyle ? {
        display: window.getComputedStyle(thumb).display,
        visibility: window.getComputedStyle(thumb).visibility,
        opacity: window.getComputedStyle(thumb).opacity,
        border: window.getComputedStyle(thumb).border,
        background: window.getComputedStyle(thumb).backgroundColor
      } : null
    };
  }
  function watchStatus(field) {
    var status = artStatus(field);
    var thumb = status ? status.querySelector('.sunstreaker-art-upload__status-thumb') : null;
    var observer;
    if (!status || !window.MutationObserver) return null;
    observer = new MutationObserver(function (mutations) {
      if (window.console && typeof window.console.log === 'function') {
        window.console.log('[sunstreaker-front-back-status]', mutations.map(function (mutation) {
          return {
            type: mutation.type,
            target: mutation.target && mutation.target.className ? mutation.target.className : mutation.target.nodeName,
            attributeName: mutation.attributeName || ''
          };
        }), statusDebugSnapshot(field, 'mutation'));
      }
    });
    observer.observe(status, {
      attributes: true,
      childList: true,
      subtree: true,
      attributeFilter: ['hidden', 'class', 'src', 'style']
    });
    if (thumb) {
      thumb.addEventListener('load', function () {
        if (window.console && typeof window.console.log === 'function') {
          window.console.log('[sunstreaker-front-back-status]', 'thumb-load', statusDebugSnapshot(field, 'load'));
        }
      });
      thumb.addEventListener('error', function () {
        if (window.console && typeof window.console.log === 'function') {
          window.console.log('[sunstreaker-front-back-status]', 'thumb-error', statusDebugSnapshot(field, 'error'));
        }
      });
    }
    if (window.console && typeof window.console.log === 'function') {
      window.console.log('[sunstreaker-front-back-status]', 'watching', statusDebugSnapshot(field, 'watching'));
    }
    return observer;
  }
  function supportsHoverUi() {
    return !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
  }
  function clearUiHideTimer() {
    if (!state.hideUiTimer) return;
    window.clearTimeout(state.hideUiTimer);
    state.hideUiTimer = 0;
  }
  function showUi() {
    clearUiHideTimer();
    if (state.uiVisible) return;
    state.uiVisible = true;
    scheduleRender();
  }
  function hideUi() {
    clearUiHideTimer();
    if (!state.uiVisible) return;
    state.uiVisible = false;
    scheduleRender();
  }
  function scheduleUiHide(delay) {
    if (supportsHoverUi() || state.drag) return;
    clearUiHideTimer();
    state.hideUiTimer = window.setTimeout(function () {
      state.hideUiTimer = 0;
      if (!state.drag) hideUi();
    }, Math.max(0, delay || 0));
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
    return { x: x, y: y, w: w, h: h };
  }

  function sanitizeTransform(transform) {
    if (!transform || typeof transform !== 'object') return null;
    if (
      !Object.prototype.hasOwnProperty.call(transform, 'x') ||
      !Object.prototype.hasOwnProperty.call(transform, 'y') ||
      !Object.prototype.hasOwnProperty.call(transform, 'w') ||
      !Object.prototype.hasOwnProperty.call(transform, 'h')
    ) {
      return null;
    }
    var x = Number(transform.x), y = Number(transform.y), w = Number(transform.w), h = Number(transform.h);
    if (!Number.isFinite(x)) x = 0;
    if (!Number.isFinite(y)) y = 0;
    if (!Number.isFinite(w)) w = 1;
    if (!Number.isFinite(h)) h = 1;
    w = clamp(w, 0.05, 1);
    h = clamp(h, 0.05, 1);
    x = clamp(x, 0, 1 - w);
    y = clamp(y, 0, 1 - h);
    return { x: x, y: y, w: w, h: h };
  }

  function parseTransform(value) {
    if (!value) return null;
    try {
      return sanitizeTransform(JSON.parse(value));
    } catch (error) {
      return null;
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

  function boundaryData(field) {
    var boundaries = settings.boundaries || {};
    return normalizeRect(boundaries[field], defaultBoundaries[field]);
  }

  function artFileInput(field) { return document.getElementById('sunstreaker_' + field + '_art_file'); }
  function artUrlInput(field) { return document.getElementById('sunstreaker_' + field + '_art_url'); }
  function artTransformInput(field) { return document.getElementById('sunstreaker_' + field + '_transform'); }
  function artButton(field) { return document.getElementById('sunstreaker_' + field + '_art_button'); }
  function artClearButton(field) { return document.getElementById('sunstreaker_' + field + '_art_clear'); }
  function artStatus(field) { return document.getElementById('sunstreaker_' + field + '_art_status'); }
  function artStatusThumb(field) {
    var el = artStatus(field);
    var thumb = el ? el.querySelector('.sunstreaker-art-upload__status-thumb') : null;
    if (!el) return null;
    if (!thumb) {
      thumb = document.createElement('img');
      thumb.className = 'sunstreaker-art-upload__status-thumb';
      thumb.alt = '';
      thumb.loading = 'eager';
      thumb.decoding = 'async';
      thumb.hidden = true;
      el.insertBefore(thumb, el.firstChild);
    }
    return thumb;
  }
  function artStatusText(field) {
    var el = artStatus(field);
    return el ? el.querySelector('.sunstreaker-art-upload__status-text') : null;
  }

  function setFieldStatus(field, message, isError) {
    var el = artStatus(field);
    var art = state.arts[field];
    var thumb = artStatusThumb(field);
    var text = artStatusText(field);
    var nextStatus;
    var previousStatus;
    var thumbSrc;
    var showThumb;

    if (!el || !art) return;

    art.statusMessage = message || '';
    art.statusError = !!isError;
    showThumb = !art.loading && !art.statusError && art.statusMessage === '' && art.url !== '';
    nextStatus = {
      hidden: !showThumb && art.statusMessage === '',
      error: art.statusError,
      showThumb: showThumb,
      message: art.statusMessage,
      thumbUrl: showThumb ? art.url : ''
    };
    previousStatus = art.renderedStatus;

    if (
      previousStatus &&
      previousStatus.hidden === nextStatus.hidden &&
      previousStatus.error === nextStatus.error &&
      previousStatus.showThumb === nextStatus.showThumb &&
      previousStatus.message === nextStatus.message &&
      previousStatus.thumbUrl === nextStatus.thumbUrl
    ) {
      return;
    }

    el.hidden = nextStatus.hidden;
    el.classList.toggle('is-error', art.statusError);
    el.classList.toggle('has-thumb', showThumb);

    if (thumb) {
      if (showThumb) {
        thumbSrc = thumb.getAttribute('src') || '';
        if (thumbSrc !== art.url) thumb.setAttribute('src', art.url);
        if (thumb.hidden) thumb.hidden = false;
      } else {
        if (!thumb.hidden) thumb.hidden = true;
        if (art.url === '' && thumb.hasAttribute('src')) thumb.removeAttribute('src');
      }
    }

    if (text) {
      text.textContent = showThumb ? '' : art.statusMessage;
      text.hidden = showThumb || art.statusMessage === '';
    }

    art.renderedStatus = nextStatus;
  }

  function syncButtons(field) {
    var button = artButton(field);
    var clear = artClearButton(field);
    var hasUrl = state.arts[field].url !== '';
    if (button) {
      button.textContent = hasUrl ? 'Replace artwork' : 'Upload artwork';
      button.disabled = !!state.arts[field].loading;
    }
    if (clear) {
      clear.hidden = !hasUrl;
      clear.disabled = !!state.arts[field].loading;
    }
  }

  function dispatchInputEvents(input) {
    if (!input) return;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function writeTransform(field, transform) {
    var clean = sanitizeTransform(transform);
    var input = artTransformInput(field);
    state.arts[field].transform = clean;
    if (input) input.value = clean ? JSON.stringify(clean) : '';
  }

  function transformsEqual(a, b) {
    a = sanitizeTransform(a);
    b = sanitizeTransform(b);
    if (!a && !b) return true;
    if (!a || !b) return false;
    return Math.abs(a.x - b.x) < 0.000001 &&
      Math.abs(a.y - b.y) < 0.000001 &&
      Math.abs(a.w - b.w) < 0.000001 &&
      Math.abs(a.h - b.h) < 0.000001;
  }

  function isFullBoxTransform(transform) {
    return !!transform &&
      Math.abs(Number(transform.x)) < 0.000001 &&
      Math.abs(Number(transform.y)) < 0.000001 &&
      Math.abs(Number(transform.w) - 1) < 0.000001 &&
      Math.abs(Number(transform.h) - 1) < 0.000001;
  }

  function boundaryPixelSize(field) {
    var els = state.els[field];
    var rect;
    if (els && els.boundary) {
      rect = els.boundary.getBoundingClientRect();
      if (rect.width > 0 && rect.height > 0) {
        return { w: rect.width, h: rect.height };
      }
    }
    return null;
  }

  function defaultTransform(field) {
    var art = state.arts[field];
    var ratio = art.natural.h / Math.max(art.natural.w, 1);
    var boundarySize = boundaryPixelSize(field);
    var w = 1;
    var h;
    if (!boundarySize) return null;
    h = ratio * (boundarySize.w / Math.max(boundarySize.h, 1));
    if (!Number.isFinite(ratio) || ratio <= 0) {
      w = 1;
      h = 1;
    } else if (h > 1) {
      w = 1 / h;
      h = 1;
    }

    return sanitizeTransform({
      x: (1 - w) / 2,
      y: 0,
      w: w,
      h: h
    });
  }

  function fitTransformToArtwork(field, transform) {
    var art = state.arts[field];
    var boundarySize = boundaryPixelSize(field);
    var next = sanitizeTransform(transform);
    var ratio = art.natural.h / Math.max(art.natural.w, 1);
    var centerX, centerY, fittedH, fittedW, currentRatio;

    if (!next || !boundarySize || art.natural.w <= 0 || art.natural.h <= 0 || !Number.isFinite(ratio) || ratio <= 0) {
      return next;
    }

    currentRatio = (next.h * boundarySize.h) / Math.max(next.w * boundarySize.w, 1);
    if (Math.abs(currentRatio - ratio) <= 0.01) return next;

    centerX = next.x + (next.w / 2);
    centerY = next.y + (next.h / 2);
    fittedH = (next.w * boundarySize.w * ratio) / Math.max(boundarySize.h, 1);

    if (fittedH <= 1) {
      next.h = fittedH;
    } else {
      fittedW = (next.h * boundarySize.h) / Math.max(ratio * boundarySize.w, 1);
      next.w = Math.min(1, fittedW);
    }

    next.x = centerX - (next.w / 2);
    next.y = centerY - (next.h / 2);
    return sanitizeTransform(next);
  }

  function loadNaturalSize(field, url) {
    var art = state.arts[field];
    var img;
    if (!url || (art.natural.w > 0 && art.natural.h > 0)) return;
    img = new Image();
    img.onload = function () {
      art.natural = {
        w: img.naturalWidth || img.width || 0,
        h: img.naturalHeight || img.height || 0
      };
      if (!art.transform || isFullBoxTransform(art.transform)) writeTransform(field, defaultTransform(field));
      scheduleRender();
    };
    img.src = url;
  }

  function syncFieldFromInputs(field) {
    var urlInput = artUrlInput(field);
    var transformInput = artTransformInput(field);
    var nextUrl = urlInput ? String(urlInput.value || '').trim() : '';
    var nextTransform = transformInput ? parseTransform(transformInput.value) : null;
    var art = state.arts[field];

    if (art.url !== nextUrl) {
      art.url = nextUrl;
      art.natural = { w: 0, h: 0 };
      art.resetTransform = false;
      art.statusMessage = '';
      art.statusError = false;
      if (nextUrl) loadNaturalSize(field, nextUrl);
    }

    if (nextTransform) {
      art.transform = nextTransform;
    } else if (!nextUrl) {
      art.transform = null;
    }

    syncButtons(field);
    setFieldStatus(field, art.statusMessage, art.statusError);
  }

  function editingBoundaries() {
    return !!document.querySelector('.sunstreaker-preview-layer.is-editing');
  }

  function createFieldEl(field) {
    var boundary = document.createElement('div');
    var label = document.createElement('div');
    var frame = document.createElement('div');
    var centerLine = document.createElement('div');
    var art = document.createElement('div');
    var image = document.createElement('img');
    var remove = document.createElement('button');
    var handle = document.createElement('button');

    boundary.className = 'sunstreaker-front-back__boundary';
    boundary.dataset.field = field;

    label.className = 'sunstreaker-front-back__label';
    label.textContent = fieldLabels[field] || field;

    frame.className = 'sunstreaker-front-back__frame';
    centerLine.className = 'sunstreaker-front-back__center-line';

    art.className = 'sunstreaker-front-back__art';

    image.className = 'sunstreaker-front-back__image';
    image.alt = fieldLabels[field] || field;
    image.draggable = false;

    remove.type = 'button';
    remove.className = 'sunstreaker-front-back__remove';
    remove.setAttribute('aria-label', 'Remove ' + (fieldLabels[field] || field) + ' artwork');
    remove.textContent = '\u00D7';

    handle.type = 'button';
    handle.className = 'sunstreaker-front-back__handle';
    handle.setAttribute('aria-label', (fieldLabels[field] || field) + ' resize handle');

    frame.appendChild(centerLine);
    art.appendChild(image);
    art.appendChild(remove);
    art.appendChild(handle);
    frame.appendChild(art);
    boundary.appendChild(label);
    boundary.appendChild(frame);

    state.els[field] = {
      boundary: boundary,
      centerLine: centerLine,
      art: art,
      image: image,
      remove: remove,
      handle: handle
    };

    return boundary;
  }

  function onFrameMouseEnter() {
    if (!supportsHoverUi()) return;
    showUi();
  }

  function onFrameMouseLeave() {
    if (!supportsHoverUi() || state.drag || editingBoundaries()) return;
    hideUi();
  }

  function onFrameTouchStart() {
    if (supportsHoverUi()) return;
    showUi();
    scheduleUiHide(1000);
  }

  function bindFrameUi(frame) {
    if (!frame || frame === state.frameUiBound) return;
    state.frameUiBound = frame;
    frame.addEventListener('mouseenter', onFrameMouseEnter);
    frame.addEventListener('mouseleave', onFrameMouseLeave);
    frame.addEventListener('touchstart', onFrameTouchStart, { passive: true });
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
    if (window.getComputedStyle(frame).position === 'static') frame.style.position = 'relative';
    bindFrameUi(frame);
    if (supportsHoverUi() && !state.drag && !editingBoundaries()) {
      state.uiVisible = !!(frame.matches && frame.matches(':hover'));
    }

    if (!state.stage) {
      state.stage = document.createElement('div');
      state.stage.className = 'sunstreaker-front-back-layer';
      fields.forEach(function (field) {
        state.stage.appendChild(createFieldEl(field));
      });
      state.stage.addEventListener('pointerdown', onPointerDown, { passive: false });
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

  function renderField(field) {
    var els = state.els[field];
    var art = state.arts[field];
    var boundary = boundaryData(field);
    var centered = !!(state.drag && state.drag.field === field && state.drag.centered);
    var controlsEnabled = !editingBoundaries();
    var renderTransform;

    if (!els) return;

    els.boundary.hidden = art.url === '';
    els.boundary.style.left = (boundary.x * 100) + '%';
    els.boundary.style.top = (boundary.y * 100) + '%';
    els.boundary.style.width = (boundary.w * 100) + '%';
    els.boundary.style.height = (boundary.h * 100) + '%';
    els.boundary.classList.toggle('is-suspended', editingBoundaries());
    els.art.style.pointerEvents = controlsEnabled ? 'auto' : 'none';
    els.remove.disabled = !controlsEnabled;
    els.handle.disabled = !controlsEnabled;

    if (art.url === '') {
      els.image.removeAttribute('src');
      els.art.hidden = true;
      els.image.hidden = true;
      return;
    }

    if (art.natural.w <= 0 || art.natural.h <= 0) {
      loadNaturalSize(field, art.url);
    } else if (art.resetTransform && !(state.drag && state.drag.field === field)) {
      renderTransform = defaultTransform(field);
      if (renderTransform) {
        writeTransform(field, renderTransform);
        art.resetTransform = false;
      }
    } else if (!(state.drag && state.drag.field === field)) {
      renderTransform = currentTransform(field);
      if (renderTransform && !transformsEqual(art.transform, renderTransform)) {
        writeTransform(field, renderTransform);
      }
    }

    if (!art.transform) {
      els.art.hidden = true;
      return;
    }

    els.art.hidden = false;
    els.art.style.left = (art.transform.x * 100) + '%';
    els.art.style.top = (art.transform.y * 100) + '%';
    els.art.style.width = (art.transform.w * 100) + '%';
    els.art.style.height = (art.transform.h * 100) + '%';
    if (els.image.getAttribute('src') !== art.url) els.image.setAttribute('src', art.url);
    els.image.hidden = false;
    els.centerLine.hidden = !(centered && state.uiVisible);
    debugLog(field, 'render', false);
  }

  function render() {
    fields.forEach(syncFieldFromInputs);
    if (!ensureStage()) return;
    state.stage.classList.toggle('is-suspended', editingBoundaries());
    state.stage.classList.toggle('is-ui-visible', state.uiVisible && !editingBoundaries());
    fields.forEach(renderField);
  }

  function scheduleRender() {
    if (state.rafId) return;
    state.rafId = window.requestAnimationFrame(function () {
      state.rafId = 0;
      render();
    });
  }

  function imageFileType(file) {
    var type = file && file.type ? String(file.type).toLowerCase() : '';
    if (type.indexOf('image/png') === 0) return 'image/png';
    if (type.indexOf('image/webp') === 0) return 'image/webp';
    if (type.indexOf('image/jpeg') === 0 || type.indexOf('image/jpg') === 0) return 'image/jpeg';
    return '';
  }

  function colorsClose(a, b, threshold) {
    return Math.abs(a.r - b.r) <= threshold &&
      Math.abs(a.g - b.g) <= threshold &&
      Math.abs(a.b - b.b) <= threshold;
  }

  function canvasPixel(data, width, x, y) {
    var index = ((y * width) + x) * 4;
    return {
      r: data[index],
      g: data[index + 1],
      b: data[index + 2],
      a: data[index + 3]
    };
  }

  function canvasBackgroundColor(data, width, height) {
    var threshold = 34;
    var corners = [
      canvasPixel(data, width, 0, 0),
      canvasPixel(data, width, Math.max(0, width - 1), 0),
      canvasPixel(data, width, 0, Math.max(0, height - 1)),
      canvasPixel(data, width, Math.max(0, width - 1), Math.max(0, height - 1))
    ];
    var best = null;
    var bestCount = 0;
    corners.forEach(function (candidate) {
      var count = 0;
      if (candidate.a < 250) return;
      corners.forEach(function (corner) {
        if (corner.a >= 250 && colorsClose(candidate, corner, threshold)) count += 1;
      });
      if (count > bestCount) {
        best = candidate;
        bestCount = count;
      }
    });
    return bestCount >= 3 ? best : null;
  }

  function canvasToBlob(canvas, type) {
    return new Promise(function (resolve, reject) {
      if (!canvas || typeof canvas.toBlob !== 'function') {
        reject(new Error('Canvas export is not available.'));
        return;
      }
      canvas.toBlob(function (blob) {
        if (blob) resolve(blob);
        else reject(new Error('Canvas export failed.'));
      }, type === 'image/jpeg' ? 'image/jpeg' : 'image/png', 0.92);
    });
  }

  function loadFileImage(file) {
    return new Promise(function (resolve, reject) {
      var url = window.URL && window.URL.createObjectURL ? window.URL.createObjectURL(file) : '';
      var image = new Image();
      image.onload = function () {
        if (url) window.URL.revokeObjectURL(url);
        resolve(image);
      };
      image.onerror = function () {
        if (url) window.URL.revokeObjectURL(url);
        reject(new Error('Could not read artwork image.'));
      };
      image.src = url;
    });
  }

  function cropArtworkFile(file) {
    var type = imageFileType(file);
    var maxPixels = 20000000;
    if (!type || !window.URL || !document.createElement('canvas').getContext) {
      return Promise.resolve(file);
    }

    return loadFileImage(file).then(function (image) {
      var width = image.naturalWidth || image.width || 0;
      var height = image.naturalHeight || image.height || 0;
      var sourceCanvas, sourceContext, imageData, data, background;
      var hasTransparency = false;
      var minX = width, minY = height, maxX = -1, maxY = -1;
      var x, y, pixel, cropW, cropH, croppedCanvas, croppedContext;

      if (width <= 1 || height <= 1 || (width * height) > maxPixels) return file;

      sourceCanvas = document.createElement('canvas');
      sourceCanvas.width = width;
      sourceCanvas.height = height;
      sourceContext = sourceCanvas.getContext('2d', { willReadFrequently: true });
      if (!sourceContext) return file;
      sourceContext.drawImage(image, 0, 0);
      imageData = sourceContext.getImageData(0, 0, width, height);
      data = imageData.data;
      background = canvasBackgroundColor(data, width, height);

      for (y = 0; y < height; y += 1) {
        for (x = 0; x < width; x += 1) {
          if (data[((y * width) + x) * 4 + 3] < 250) {
            hasTransparency = true;
            break;
          }
        }
        if (hasTransparency) break;
      }

      if (!hasTransparency && !background) return file;

      for (y = 0; y < height; y += 1) {
        for (x = 0; x < width; x += 1) {
          pixel = canvasPixel(data, width, x, y);
          if (hasTransparency) {
            if (pixel.a < 16) continue;
          } else if (colorsClose(pixel, background, 34)) {
            continue;
          }
          if (x < minX) minX = x;
          if (y < minY) minY = y;
          if (x > maxX) maxX = x;
          if (y > maxY) maxY = y;
        }
      }

      if (maxX < minX || maxY < minY) return file;
      cropW = maxX - minX + 1;
      cropH = maxY - minY + 1;
      if (cropW === width && cropH === height) return file;

      croppedCanvas = document.createElement('canvas');
      croppedCanvas.width = cropW;
      croppedCanvas.height = cropH;
      croppedContext = croppedCanvas.getContext('2d');
      if (!croppedContext) return file;
      croppedContext.drawImage(sourceCanvas, minX, minY, cropW, cropH, 0, 0, cropW, cropH);

      return canvasToBlob(croppedCanvas, type).then(function (blob) {
        var extension = type === 'image/jpeg' ? '.jpg' : '.png';
        var name = file.name ? file.name.replace(/\.[^.]+$/, extension) : 'artwork' + extension;
        if (typeof File === 'function') {
          return new File([blob], name, { type: blob.type || type, lastModified: Date.now() });
        }
        blob.name = name;
        return blob;
      });
    }).catch(function () {
      return file;
    });
  }

  function uploadArtwork(field, file) {
    var data;
    var art = state.arts[field];

    if (!file) return;

    art.loading = true;
    state.pendingUploads += 1;
    syncButtons(field);
    setFieldStatus(field, statusText('uploadingArt', 'Uploading artwork…'), false);

    cropArtworkFile(file).then(function (uploadFile) {
      data = new FormData();
      data.append('action', settings.uploadAction || 'sunstreaker_upload_front_back_art');
      data.append('_ajax_nonce', settings.nonce || '');
      data.append('product_id', String(settings.productId || ''));
      data.append('slot', field);
      data.append('image', uploadFile, uploadFile.name || file.name || 'artwork.png');

      return fetch(settings.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
      });
    }).then(function (response) {
      return response.json();
    }).then(function (payload) {
      if (!payload || !payload.success || !payload.data || !payload.data.url) {
        throw new Error((payload && payload.data && payload.data.message) || statusText('uploadArtError', 'Could not upload artwork.'));
      }

      art.url = String(payload.data.url || '');
      art.natural = {
        w: Number(payload.data.width || 0),
        h: Number(payload.data.height || 0)
      };
      art.resetTransform = true;
      if (artUrlInput(field)) {
        artUrlInput(field).value = art.url;
        dispatchInputEvents(artUrlInput(field));
      }
      writeTransform(field, null);
      setFieldStatus(field, '', false);
      if (payload.data.trim) debugLog(field, 'upload-trim', true);
      showUi();
      if (!supportsHoverUi()) scheduleUiHide(1000);
      scheduleRender();
    }).catch(function (error) {
      setFieldStatus(field, error && error.message ? error.message : statusText('uploadArtError', 'Could not upload artwork.'), true);
    }).finally(function () {
      art.loading = false;
      state.pendingUploads = Math.max(0, state.pendingUploads - 1);
      syncButtons(field);
      setFieldStatus(field, art.statusMessage, art.statusError);
      if (artFileInput(field)) artFileInput(field).value = '';
    });
  }

  function clearArtwork(field) {
    if (artUrlInput(field)) {
      artUrlInput(field).value = '';
      dispatchInputEvents(artUrlInput(field));
    }
    if (artTransformInput(field)) artTransformInput(field).value = '';
    state.arts[field] = freshArtState();
    syncButtons(field);
    setFieldStatus(field, '', false);
    scheduleRender();
  }

  function bindUploadControls() {
    fields.forEach(function (field) {
      var button = artButton(field);
      var file = artFileInput(field);
      var clear = artClearButton(field);

      if (button && file) {
        button.addEventListener('click', function (event) {
          event.preventDefault();
          file.click();
        });
      }

      if (file) {
        file.addEventListener('change', function () {
          var chosen = file.files && file.files[0] ? file.files[0] : null;
          if (!chosen) return;
          uploadArtwork(field, chosen);
        });
      }

      if (clear) {
        clear.addEventListener('click', function (event) {
          event.preventDefault();
          clearArtwork(field);
        });
      }
    });
  }

  function bindSubmitGuard() {
    var form = document.querySelector('form.cart');
    if (!form) return;

    form.addEventListener('submit', function (event) {
      fields.forEach(function (field) {
        if (state.arts[field].url === '') return;
        if (artTransformInput(field) && artTransformInput(field).value !== '') return;
        writeTransform(field, currentTransform(field));
      });
      if (state.pendingUploads > 0) {
        event.preventDefault();
        alert(statusText('artUploadPending', 'Artwork is still uploading. Please wait.'));
      }
    });
  }

  function currentTransform(field) {
    var art = state.arts[field];
    if (art.transform) return fitTransformToArtwork(field, art.transform);
    if (art.natural.w <= 0 || art.natural.h <= 0) return null;
    return sanitizeTransform(defaultTransform(field));
  }

  function onPointerDown(event) {
    var boundary = event.target.closest('.sunstreaker-front-back__boundary');
    var field;
    var target;
    var rect;
    var transform;

    if (editingBoundaries()) return;
    if (!boundary || !state.stage || !state.stage.contains(boundary)) return;
    if (typeof event.button === 'number' && event.button !== 0) return;

    field = boundary.dataset.field;
    if (!field || !state.arts[field] || state.arts[field].url === '') return;

    target = event.target;
    showUi();

    if (target.closest('.sunstreaker-front-back__remove')) {
      event.preventDefault();
      clearArtwork(field);
      if (!supportsHoverUi()) scheduleUiHide(1000);
      return;
    }
    if (!target.closest('.sunstreaker-front-back__art') && !target.closest('.sunstreaker-front-back__handle')) return;

    rect = boundary.getBoundingClientRect();
    if (!rect.width || !rect.height) return;

    transform = currentTransform(field);
    if (!transform) return;
    if (!transformsEqual(state.arts[field].transform, transform)) writeTransform(field, transform);
    debugLog(field, 'pointerdown', true);
    state.drag = {
      pointerId: event.pointerId,
      field: field,
      mode: target.closest('.sunstreaker-front-back__handle') ? 'resize' : 'move',
      startX: event.clientX,
      startY: event.clientY,
      boundaryWidth: rect.width,
      boundaryHeight: rect.height,
      origin: transform,
      centered: false,
      target: target
    };

    if (target.setPointerCapture) target.setPointerCapture(event.pointerId);
    event.preventDefault();
  }

  function onPointerMove(event) {
    var dxPx, dyPx, next, art, ratio, deltaPx, maxWpx, maxHpx, newWpx, newHpx, snapThreshold;
    if (!state.drag) return;

    art = state.arts[state.drag.field];
    next = sanitizeTransform(state.drag.origin);
    dxPx = event.clientX - state.drag.startX;
    dyPx = event.clientY - state.drag.startY;

    if (state.drag.mode === 'move') {
      next.x = state.drag.origin.x + (dxPx / state.drag.boundaryWidth);
      next.y = state.drag.origin.y + (dyPx / state.drag.boundaryHeight);
      next = sanitizeTransform(next);
    } else {
      ratio = art.natural.h / Math.max(art.natural.w, 1);
      deltaPx = Math.max(dxPx, dyPx);
      newWpx = Math.max(20, (state.drag.origin.w * state.drag.boundaryWidth) + deltaPx);
      newHpx = ratio > 0 ? newWpx * ratio : newWpx;
      maxWpx = Math.max(20, (1 - state.drag.origin.x) * state.drag.boundaryWidth);
      maxHpx = Math.max(20, (1 - state.drag.origin.y) * state.drag.boundaryHeight);

      if (newWpx > maxWpx) {
        newWpx = maxWpx;
        newHpx = ratio > 0 ? newWpx * ratio : newWpx;
      }
      if (newHpx > maxHpx) {
        newHpx = maxHpx;
        newWpx = ratio > 0 ? newHpx / ratio : newHpx;
      }

      next.w = newWpx / state.drag.boundaryWidth;
      next.h = newHpx / state.drag.boundaryHeight;
      next = sanitizeTransform(next);
    }

    snapThreshold = 10 / Math.max(1, state.drag.boundaryWidth);
    state.drag.centered = Math.abs((next.x + (next.w / 2)) - 0.5) <= snapThreshold;
    if (state.drag.mode === 'move' && state.drag.centered) {
      next.x = (1 - next.w) / 2;
      next = sanitizeTransform(next);
    }

    writeTransform(state.drag.field, next);
    scheduleRender();
    debugLog(state.drag.field, state.drag.mode, false);
    event.preventDefault();
  }

  function endPointer(event) {
    if (!state.drag) return;
    if (state.drag.target && typeof state.drag.target.releasePointerCapture === 'function' && event && event.pointerId === state.drag.pointerId) {
      try { state.drag.target.releasePointerCapture(event.pointerId); } catch (error) {}
    }
    state.drag = null;
    scheduleRender();
    if (supportsHoverUi()) {
      if (!state.frame || !state.frame.matches || !state.frame.matches(':hover')) hideUi();
    } else {
      scheduleUiHide(1000);
    }
  }

  function bindObservers() {
    var gallery = document.querySelector('.woocommerce-product-gallery');
    if (!gallery || state.observer) return;

    if (window.MutationObserver) {
      state.observer = new MutationObserver(scheduleRender);
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
    fields.forEach(syncFieldFromInputs);
    bindUploadControls();
    bindSubmitGuard();
    bindObservers();
    document.addEventListener('pointermove', onPointerMove, { passive: false });
    document.addEventListener('pointerup', endPointer);
    document.addEventListener('pointercancel', endPointer);
    window.addEventListener('resize', scheduleRender);
    window.addEventListener('load', scheduleRender);
    window.sunstreakerFrontBackDebug = function (field) {
      if (field === 'back') return debugSnapshot('back', 'manual');
      if (field === 'front') return debugSnapshot('front', 'manual');
      return {
        front: debugSnapshot('front', 'manual'),
        back: debugSnapshot('back', 'manual')
      };
    };
    window.sunstreakerFrontBackStatusDebug = function (field) {
      if (field === 'back') return statusDebugSnapshot('back', 'manual');
      return statusDebugSnapshot('front', 'manual');
    };
    window.sunstreakerFrontBackWatchStatus = function (field) {
      if (field === 'back') return watchStatus('back');
      return watchStatus('front');
    };
    scheduleRender();
    window.setTimeout(scheduleRender, 150);
    window.setTimeout(scheduleRender, 600);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
