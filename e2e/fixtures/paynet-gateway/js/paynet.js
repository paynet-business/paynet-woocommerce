function setCookie(cname, cvalue, exdays) {
  var d = new Date();
  d.setTime(d.getTime() + (exdays*24*60*60*1000));
  var expires = "expires="+ d.toUTCString();
  document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}

function getCookie(cname) {
  var name = cname + "=";
  var decodedCookie = decodeURIComponent(document.cookie);
  var ca = decodedCookie.split(';');
  for(var i = 0; i <ca.length; i++) {
    var c = ca[i];
    while (c.charAt(0) == ' ') {
      c = c.substring(1);
    }
    if (c.indexOf(name) == 0) {
      return c.substring(name.length, c.length);
    }
  }
  return "";
}

jQuery(document).ready(function(){
  jQuery('head').append("<style>ul.paynet-awailable-options{list-style:none;display:none;margin:0;padding:0}ul.paynet-awailable-options.active{display:block}ul.paynet-awailable-options li{border: 1px solid #ccc;padding: 5px 15px;margin: 0 0 5px !important}ul.paynet-awailable-options li label,ul.paynet-awailable-options li input{display: inline-block}.toggle-paynet-options{border: 1px solid #d3ced2;width: 30px;height: 30px;display: inline-block;line-height: 28px;text-align: center;vertical-align: middle;font-size: 24px;cursor: pointer;margin-left: 5px;}</style>");
});

var paynetMethods = "";

jQuery.ajax({
  type:'POST',
  url: "https://" + window.location.hostname + "/wp-content/plugins/paynet-gateway/get-available-methods.php",
  success: function(value) {
    var available_methods = JSON.parse(value);
    for (var method in available_methods){
        if (available_methods.hasOwnProperty(method)) {
             paynetMethods+="<li>"+
                                "<label for='" + method + "'>" +available_methods[method] + "</label>"+
                                "<input id='" + method + "' "+
                                    "type='radio' "+
                                    "name='paynetMethods' "+
                                    "value='" + method + "'>"+
                              "</li>";
        }
    }

    if(paynetMethods.length){
      jQuery('input#payment_method_paynet').parent().find('label').append("<span class='toggle-paynet-options'>+</span>");
      jQuery('input#payment_method_paynet').parent().append("<ul class='paynet-awailable-options'>" + paynetMethods + "</ul>");
    }
  }
});

jQuery(document).ajaxComplete(function(){
  var appended = jQuery('input#payment_method_paynet').parent().find('.paynet-awailable-options');
  if(!appended.length){
    jQuery('input#payment_method_paynet').parent().find('label').append("<span class='toggle-paynet-options'>+</span>");
    jQuery('input#payment_method_paynet').parent().append("<ul class='paynet-awailable-options'>" + paynetMethods + "</ul>");
  }
});

jQuery(document).on('click','.toggle-paynet-options', function(e) {
  if(jQuery('ul.paynet-awailable-options').hasClass('active')){
    jQuery('.toggle-paynet-options').html('+');
    jQuery('ul.paynet-awailable-options').removeClass('active');
  } else {
    jQuery('.toggle-paynet-options').html('-');
    jQuery('ul.paynet-awailable-options').addClass('active');
  }
});

jQuery(document).on('change','input[name="payment_method"]', function(e) {
  if(jQuery('input#payment_method_paynet').is(':checked')) {
    jQuery('.toggle-paynet-options').html('-');
    jQuery('ul.paynet-awailable-options').addClass('active');
  } else {
    jQuery('.toggle-paynet-options').html('+');
    jQuery('ul.paynet-awailable-options').removeClass('active');
  }
});

jQuery(document).on('change','input[name="paynetMethods"]', function(e) {
    setCookie('PAYNET_MONEY_TYPE', jQuery(this).val(),1);
});
