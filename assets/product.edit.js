jQuery(function($){
  var config = window.sunstreakerProductEdit || {};
  var fontConfig = config.fonts || {};
  var strings = config.strings || {};
  var selectedLogosByLocation = $.isPlainObject(config.selectedLogosByLocation) ? $.extend(true, {}, config.selectedLogosByLocation) : {};
  var mediaFrame = null;
  var activeLocationKey = '';

  function syncSunstreakerAddon(){
    var enabled = $('#_sunstreaker_enabled').is(':checked');
    $('.sunstreaker-addon-wrap').toggle(enabled);
    syncFeatureControls();
  }

  function syncFeatureControls(){
    var enabled = $('#_sunstreaker_enabled').is(':checked');
    var useNameNumber = $('#_sunstreaker_use_name_number').is(':checked');
    var useLogos = $('#_sunstreaker_use_logos').is(':checked');
    var useRightChest = $('#_sunstreaker_use_right_chest_text').is(':checked');
    var useFrontBack = $('#_sunstreaker_use_front_back').is(':checked');

    $('.sunstreaker-name-number-settings').toggle(enabled && useNameNumber);
    $('.sunstreaker-logo-library-wrap').toggle(enabled && useLogos);
    $('.sunstreaker-right-chest-settings').toggle(enabled && useRightChest);
    $('.sunstreaker-front-back-settings').toggle(enabled && useFrontBack);

    $('.sunstreaker-logo-location').each(function(){
      var $location = $(this);
      var isEnabled = $location.find('.sunstreaker-logo-location__toggle').is(':checked');
      $location.toggleClass('is-enabled', isEnabled);
      $location.find('.sunstreaker-logo-location__settings').toggle(enabled && useLogos && isEnabled);
    });
  }

  function fontForValue(value){
    return fontConfig[value] || null;
  }

  function applyFontStyles(){
    $('.sunstreaker-font-select').each(function(){
      var $select = $(this);
      if (!$select.length) return;

      $select.find('option').each(function(){
        var font = fontForValue(this.value);
        if (!font || !font.stack) return;
        this.style.fontFamily = font.stack;
      });

      var selected = fontForValue($select.val());
      $select.css('font-family', selected && selected.stack ? selected.stack : '');
    });
  }

  function normalizeLogo(logo){
    var id = Number(logo && logo.id);
    if (!id) return null;

    var title = '';
    if (logo && typeof logo.title === 'string' && logo.title.trim() !== '') {
      title = logo.title.trim();
    } else if (logo && typeof logo.filename === 'string' && logo.filename.trim() !== '') {
      title = logo.filename.trim();
    } else {
      title = 'Logo ' + id;
    }

    var previewUrl = '';
    if (logo && typeof logo.preview_url === 'string' && logo.preview_url !== '') {
      previewUrl = logo.preview_url;
    } else if (logo && typeof logo.url === 'string' && logo.url !== '') {
      previewUrl = logo.url;
    }

    var thumbUrl = '';
    if (logo && typeof logo.thumb_url === 'string' && logo.thumb_url !== '') {
      thumbUrl = logo.thumb_url;
    } else if (logo && logo.sizes && logo.sizes.thumbnail && typeof logo.sizes.thumbnail.url === 'string') {
      thumbUrl = logo.sizes.thumbnail.url;
    } else if (previewUrl !== '') {
      thumbUrl = previewUrl;
    } else if (logo && typeof logo.icon === 'string') {
      thumbUrl = logo.icon;
    }

    return {
      id: id,
      title: title,
      preview_url: previewUrl,
      thumb_url: thumbUrl,
      alt: logo && typeof logo.alt === 'string' ? logo.alt : ''
    };
  }

  function logosForLocation(locationKey){
    return Array.isArray(selectedLogosByLocation[locationKey]) ? selectedLogosByLocation[locationKey] : [];
  }

  function setHiddenLogoIds(locationKey){
    $('.sunstreaker-logo-library__input[data-location-key="' + locationKey + '"]').val(
      logosForLocation(locationKey).map(function(logo){ return logo.id; }).join(',')
    );
  }

  function renderLogoList(locationKey){
    var logos = logosForLocation(locationKey);
    var $list = $('.sunstreaker-logo-library__list[data-location-key="' + locationKey + '"]');
    var $clear = $('.sunstreaker-logo-library__clear[data-location-key="' + locationKey + '"]');
    if (!$list.length) return;

    $list.empty();

    if (!logos.length) {
      $list.append(
        $('<li/>', {
          'class': 'sunstreaker-logo-library__empty',
          text: strings.emptyLogos || 'No logos selected yet.'
        })
      );
      $clear.prop('hidden', true);
      setHiddenLogoIds(locationKey);
      return;
    }

    logos.forEach(function(logo){
      var $item = $('<li/>', {
        'class': 'sunstreaker-logo-library__item',
        'data-logo-id': String(logo.id),
        'data-location-key': locationKey
      });

      if (logo.thumb_url) {
        $item.append($('<img/>', {
          'class': 'sunstreaker-logo-library__thumb',
          src: logo.thumb_url,
          alt: logo.alt || logo.title
        }));
      }

      $item.append($('<span/>', {
        'class': 'sunstreaker-logo-library__title',
        text: logo.title
      }));

      $item.append($('<button/>', {
        type: 'button',
        'class': 'button-link-delete sunstreaker-logo-library__remove',
        'data-logo-id': String(logo.id),
        'data-location-key': locationKey,
        text: strings.removeLogo || 'Remove logo'
      }));

      $list.append($item);
    });

    $clear.prop('hidden', false);
    setHiddenLogoIds(locationKey);
  }

  function uniqueLogos(logos){
    var seen = {};
    var deduped = [];

    (logos || []).forEach(function(logo){
      var normalized = normalizeLogo(logo);
      if (!normalized || seen[normalized.id]) return;
      seen[normalized.id] = true;
      deduped.push(normalized);
    });

    return deduped;
  }

  function openLogoPicker(locationKey){
    activeLocationKey = locationKey;
    if (!window.wp || !wp.media) return;

    if (!mediaFrame) {
      mediaFrame = wp.media({
        title: strings.chooseLogos || 'Choose logos',
        button: { text: strings.useSelected || 'Use selected logos' },
        library: { type: 'image' },
        multiple: true
      });

      mediaFrame.on('open', function(){
        var selection = mediaFrame.state().get('selection');
        selection.reset();
        logosForLocation(activeLocationKey).forEach(function(logo){
          var attachment;
          if (!logo || !logo.id) return;
          attachment = wp.media.attachment(logo.id);
          attachment.fetch();
          selection.add(attachment);
        });
      });

      mediaFrame.on('select', function(){
        var selection = mediaFrame.state().get('selection');
        selectedLogosByLocation[activeLocationKey] = uniqueLogos(selection.map(function(attachment){
          return attachment.toJSON();
        }));
        renderLogoList(activeLocationKey);
      });
    }

    mediaFrame.open();
  }

  $(document).on('click', '.sunstreaker-logo-library__select', function(event){
    var locationKey = String($(this).data('locationKey') || '');
    event.preventDefault();
    if (!locationKey) return;
    openLogoPicker(locationKey);
  });

  $(document).on('click', '.sunstreaker-logo-library__remove', function(event){
    var logoId;
    var locationKey = String($(this).data('locationKey') || '');
    event.preventDefault();
    if (!locationKey) return;
    logoId = Number($(this).data('logoId'));
    selectedLogosByLocation[locationKey] = logosForLocation(locationKey).filter(function(logo){
      return logo.id !== logoId;
    });
    renderLogoList(locationKey);
  });

  $(document).on('click', '.sunstreaker-logo-library__clear', function(event){
    var locationKey = String($(this).data('locationKey') || '');
    event.preventDefault();
    if (!locationKey) return;
    selectedLogosByLocation[locationKey] = [];
    renderLogoList(locationKey);
  });

  $(document).on('change', '#_sunstreaker_enabled', syncSunstreakerAddon);
  $(document).on('change', '#_sunstreaker_use_name_number, #_sunstreaker_use_logos, #_sunstreaker_use_right_chest_text, #_sunstreaker_use_front_back', syncFeatureControls);
  $(document).on('change', '.sunstreaker-logo-location__toggle', syncFeatureControls);
  $(document).on('change', '.sunstreaker-font-select', applyFontStyles);

  $.each(selectedLogosByLocation, function(locationKey, logos){
    selectedLogosByLocation[locationKey] = uniqueLogos(logos);
  });
  syncSunstreakerAddon();
  applyFontStyles();
  $('.sunstreaker-logo-library__list').each(function(){
    var locationKey = String($(this).data('locationKey') || '');
    if (!locationKey) return;
    renderLogoList(locationKey);
  });
});
