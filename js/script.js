;(function($) {
	$(document).ready(function() {
		var modal = null;
		function showModal() {
			$("#wc-voucherme-modal").jQmodal({
				modalClass: "wc-voucherme-modal",
				escapeClose: false,
				clickClose: false,
				showClose: false
			});
		}

		function hideModal() {
			$.jQmodal.close();
		}

		$("body").append('<div id="wc-voucherme-modal"><p>' + WCVoucherMeData.i18n.pleaseWait +  '</p></div>');

		$(document).on("click", ".voucherme-link", function(e) {
			e.preventDefault();
			var linkEl = $(this);
			var container = $(this).closest(".voucherme-cont");
			var voucherPInput = container.find(".voucherme-input");
				
			voucherPInput.slideToggle();
		});

		$(document).on("keyup keydown", ".voucherme-input input", function(e) {
			var key = e.which || e.keyCode || 0;

			if(key == 13) {
				e.preventDefault();
				return false;
			}
		});

		$(document).on("click", ".voucherme-process", function(e) {
			e.preventDefault();
			var processEl = $(this);
			var container = $(this).closest(".voucherme-cont");
			var voucherInput = container.find(".voucherme-input input");

			var voucher = voucherInput.val().trim();
			if(voucher == "") return false;

			if(container.hasClass("doing-ajax")) return false;
			container.addClass("doing-ajax");
			showModal();
			$.ajax({
				type: "POST",
				url: wc_checkout_params.wc_ajax_url.replace("%%endpoint%%", "voucherme_process"),
				cache: false,
				dataType: "json",
				data: container.find(":input").serialize()
			}).done(function(response){
				if(response.success) {
					$(document.body).trigger("update_checkout");
					container.addClass("has-voucher")
				} else {
					alert(response.data);
					voucherInput.val("");
				}
				container.removeClass("doing-ajax");
				hideModal();
			}).fail(function(){
				alert(WCVoucherMeData.i18n.nsError);
				container.removeClass("doing-ajax");
				hideModal();
			});
		});

		$(document).on("click", ".voucherme-remove", function(e) {
			e.preventDefault();
			var processEl = $(this);
			var container = $(this).closest(".voucherme-cont");
			var voucherInput = container.find(".voucherme-input input");

			if(container.hasClass("doing-ajax")) return false;
			container.addClass("doing-ajax");
			showModal();
			$.ajax({
				type: "POST",
				url: wc_checkout_params.wc_ajax_url.replace("%%endpoint%%", "voucherme_remove"),
				cache: false,
				dataType: "json",
				data: container.find(":input").serialize()
			}).done(function(response){
				if(response.success) {
					container.removeClass("has-voucher");
					$(document.body).trigger("update_checkout");
				} else {
					alert(response.data);
				}
				container.removeClass("doing-ajax");
				hideModal();
			}).fail(function(){
				alert(WCVoucherMeData.i18n.nsError);
				container.removeClass("doing-ajax");
				hideModal();
			});
		});
	});
})(jQuery);