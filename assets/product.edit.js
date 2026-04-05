jQuery(function($){
  var config = window.sunstreakerProductEdit || {};
  var fontConfig = config.fonts || {};
  var strings = config.strings || {};
  var selectedLogos = Array.isArray(config.selectedLogos) ? config.selectedLogos.slice() : [];
  var mediaFrame = null;

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

  function setHiddenLogoIds(){
    $('#_sunstreaker_logo_ids').val(selectedLogos.map(function(logo){ return logo.id; }).join(','));
  }

  function renderLogoList(){
    var $list = $('.sunstreaker-logo-library__list');
    var $clear = $('.sunstreaker-logo-library__clear');
    if (!$list.length) return;

    $list.empty();

    if (!selectedLogos.length) {
      $list.append(
        $('<li/>', {
          'class': 'sunstreaker-logo-library__empty',
          text: strings.emptyLogos || 'No logos selected yet.'
        })
      );
      $clear.prop('hidden', true);
      setHiddenLogoIds();
      return;
    }

    selectedLogos.forEach(function(logo){
      var $item = $('<li/>', {
        'class': 'sunstreaker-logo-library__item',
        'data-logo-id': String(logo.id)
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
        text: strings.removeLogo || 'Remove logo'
      }));

      $list.append($item);
    });

    $clear.prop('hidden', false);
    setHiddenLogoIds();
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

  function openLogoPicker(){
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
        selectedLogos.forEach(function(logo){
          var attachment;
          if (!logo || !logo.id) return;
          attachment = wp.media.attachment(logo.id);
          attachment.fetch();
          selection.add(attachment);
        });
      });

      mediaFrame.on('select', function(){
        var selection = mediaFrame.state().get('selection');
        selectedLogos = uniqueLogos(selection.map(function(attachment){
          return attachment.toJSON();
        }));
        renderLogoList();
      });
    }

    mediaFrame.open();
  }

  $(document).on('click', '.sunstreaker-logo-library__select', function(event){
    event.preventDefault();
    openLogoPicker();
  });

  $(document).on('click', '.sunstreaker-logo-library__remove', function(event){
    var logoId;
    event.preventDefault();
    logoId = Number($(this).data('logoId'));
    selectedLogos = selectedLogos.filter(function(logo){
      return logo.id !== logoId;
    });
    renderLogoList();
  });

  $(document).on('click', '.sunstreaker-logo-library__clear', function(event){
    event.preventDefault();
    selectedLogos = [];
    renderLogoList();
  });

  $(document).on('change', '#_sunstreaker_enabled', syncSunstreakerAddon);
  $(document).on('change', '#_sunstreaker_use_name_number, #_sunstreaker_use_logos, #_sunstreaker_use_right_chest_text, #_sunstreaker_use_front_back', syncFeatureControls);
  $(document).on('change', '.sunstreaker-font-select', applyFontStyles);

  selectedLogos = uniqueLogos(selectedLogos);
  syncSunstreakerAddon();
  applyFontStyles();
  renderLogoList();
});
