/**
 * Created by dzf on 20.11.2018.
 */

jQuery(document).ready(function($) {

  var enable_sorts = $('#adminForm').find('.inputordering');

  for (var i = 0; i < enable_sorts.length; i++) {
    $(enable_sorts[i]).prop("disabled", true);
    $(enable_sorts[i]).removeAttr('disabled');
  }

  $('.inputordering').keypress(function(event){
    var keycode = (event.keyCode ? event.keyCode : event.which);
    if(keycode == '13'){ // pressed a "enter" key in ordering textbox
      $.ajax({
        type: 'POST',
        dataType: 'json',
        url: 'index.php',
        data: {
          option: 'com_ajax',
          plugin: 'newwallet_sort',
          group: 'system',
          format: 'json',
          prod_pr_id: $(this).attr('id'),
          sort_value: $(this).val(),
        },
        success: function (result) {
          if (result) {
            if (result.data == "true") {
              return_res = true;
            }
            else {
              alert('Error');
              return_res = false;
            }
          }
        },

        error: function (data, status) {

        }
      });
      event.preventDefault();
    }
  });


  $('.inputordering').blur(function(event){
    $.ajax({
      type: 'POST',
      dataType: 'json',
      url: 'index.php',
      data: {
        option: 'com_ajax',
        plugin: 'newwallet_sort',
        group: 'system',
        format: 'json',
        prod_pr_id: $(this).attr('id'),
        sort_value: $(this).val(),
      },
      success: function (result) {
        if (result) {
          if (result.data == "true") {
            return_res = true;
          }
          else {
            alert('Error');
            return_res = false;
          }
        }
      },
      error: function (data, status) {

      }
    });
    event.preventDefault();
  });


});
