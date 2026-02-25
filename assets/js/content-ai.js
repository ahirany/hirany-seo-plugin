(function ($) {
	$(function () {
		var $metaBox = $('#hsp_content_ai');
		if (!$metaBox.length || typeof HSP_Content_AI === 'undefined') {
			return;
		}

		var $prompt = $('#hsp_content_ai_prompt');
		var $output = $('#hsp_content_ai_output');
		var $button = $('#hsp_content_ai_generate');
		var $focusKeyword = $('#hsp_content_ai_focus_keyword');

		$button.on('click', function () {
			var prompt = $.trim($prompt.val());
			if (!prompt) {
				alert('Please enter a prompt for Content AI.');
				return;
			}

			$button.prop('disabled', true).text('Generating...');
			$output.val('');

			$.ajax({
				method: 'POST',
				url: HSP_Content_AI.ajax_url,
				dataType: 'json',
				data: {
					action: 'hsp_generate_content_ai',
					nonce: HSP_Content_AI.nonce,
					prompt: prompt,
					focus_keyword: $focusKeyword.val(),
					post_id: $('#post_ID').val()
				}
			})
				.done(function (response) {
					if (!response || !response.success || !response.data || !response.data.text) {
						alert((response && response.data && response.data.message) || 'Content AI failed, please try again.');
						return;
					}
					$output.val(response.data.text);
				})
				.fail(function (xhr) {
					var msg = 'Content AI request failed.';
					if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					}
					alert(msg);
				})
				.always(function () {
					$button.prop('disabled', false).text('Generate content');
				});
		});
	});
})(jQuery);

