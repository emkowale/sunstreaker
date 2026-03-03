jQuery(function($){
  function syncSunstreakerAddon(){
    var $chk = $('#_sunstreaker_enabled');
    var enabled = $chk.is(':checked');
    var $wrap = $('.sunstreaker-addon-wrap');
    if (enabled) $wrap.show(); else $wrap.hide();
  }
  $(document).on('change', '#_sunstreaker_enabled', syncSunstreakerAddon);
  syncSunstreakerAddon();
});
