/* global jQuery, GFF_SS */
(function ($) {
	'use strict';

	var running = false;

	function post(action, data) {
		return $.post(GFF_SS.ajax, $.extend({ action: 'gff_ss_' + action, nonce: GFF_SS.nonce }, data || {}));
	}

	function esc(s) {
		return $('<div/>').text(s == null ? '' : String(s)).html();
	}

	function errMsg(xhr, fallback) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return xhr.responseJSON.data.message;
		}
		return fallback || 'Request failed. Please try again.';
	}

	$(window).on('beforeunload', function () {
		if (running) {
			return 'Prices are still being updated.';
		}
	});

	/* ------------------------------------------------------------------
	 * Progress modal
	 * ---------------------------------------------------------------- */

	var $modal = $('#gff-ss-progress');

	function openProgress(title, total) {
		$modal.find('.gff-ss-progress-title').text(title);
		$modal.find('.gff-ss-console').empty();
		$modal.find('.gff-ss-final').prop('hidden', true).find('.gff-ss-report').empty();
		$modal.find('.gff-ss-done').prop('hidden', true);
		$modal.find('.gff-ss-hint').text('Please keep this window open.').show();
		$modal.find('.gff-ss-current').show();
		$modal.find('.gff-ss-current-text').text('Preparing…');
		$modal.removeClass('is-done');
		setProgress(0, total || 0);
		$modal.prop('hidden', false);
		$('body').addClass('gff-ss-lock');
	}

	function setProgress(done, total) {
		var pct = total ? Math.round((done / total) * 100) : 0;
		$modal.find('.gff-ss-bar__fill').css('width', pct + '%');
		$modal.find('.gff-ss-bar__pct').text(pct + '%');
		$modal.find('.gff-ss-progress-count').text(done + ' / ' + total);
	}

	function addLogs(logs) {
		var $c = $modal.find('.gff-ss-console');
		$.each(logs || [], function (i, l) {
			$c.append('<div class="gff-ss-logline is-' + esc(l.level) + '"><time>' + esc(l.time) + '</time><span>' + esc(l.message) + '</span></div>');
		});
		$c.scrollTop($c[0].scrollHeight);
	}

	function finish(ruleId, op, report) {
		running = false;
		$modal.addClass('is-done');
		$modal.find('.gff-ss-current').hide();
		$modal.find('.gff-ss-hint').text(op === 'revert' ? 'Sale prices removed.' : 'All done!');
		var cards;
		if (op === 'revert') {
			cards = [
				['is-info', report.reverted, 'Restored'],
				['is-warning', report.revert_skipped, 'Left untouched'],
				['is-success', report.updated, 'Total in rule']
			];
		} else {
			cards = [
				['is-success', report.applied, 'Discounted'],
				['is-warning', report.skipped, 'Skipped'],
				['is-error', report.error, 'Errors']
			];
		}
		var html = '';
		$.each(cards, function (i, c) {
			html += '<div class="' + c[0] + '"><span>' + esc(c[1] || 0) + '</span>' + esc(c[2]) + '</div>';
		});
		$modal.find('.gff-ss-final').prop('hidden', false).find('.gff-ss-report').html(html);
		$modal.find('.gff-ss-done').attr('href', GFF_SS.viewUrl + '&id=' + ruleId).prop('hidden', false);
	}

	function runQueue(ruleId, op) {
		running = true;
		var retries = 0;

		(function next() {
			post('batch', { rule_id: ruleId })
				.done(function (res) {
					if (!res || !res.success) {
						addLogs([{ level: 'error', time: '', message: (res && res.data && res.data.message) || 'Unexpected response.' }]);
						running = false;
						return;
					}
					var d = res.data;
					retries = 0;
					addLogs(d.logs);
					setProgress(d.processed, d.total);
					if (d.current) {
						$modal.find('.gff-ss-current-text').text((op === 'revert' ? 'Restoring: ' : 'Editing: ') + d.current);
					}
					if (d.done) {
						setProgress(d.total, d.total);
						finish(ruleId, d.op || op, d.report || {});
					} else if (d.busy) {
						$modal.find('.gff-ss-current-text').text('Waiting for background job…');
						setTimeout(next, 1500);
					} else {
						next();
					}
				})
				.fail(function (xhr) {
					if (++retries <= 3) {
						addLogs([{ level: 'warning', time: '', message: 'Connection issue, retrying (' + retries + '/3)…' }]);
						setTimeout(next, 2000 * retries);
						return;
					}
					running = false;
					addLogs([{ level: 'error', time: '', message: errMsg(xhr) + ' The background job will finish the remaining items.' }]);
					$modal.find('.gff-ss-done').attr('href', GFF_SS.viewUrl + '&id=' + ruleId).prop('hidden', false);
				});
		})();
	}

	/* ------------------------------------------------------------------
	 * New rule form
	 * ---------------------------------------------------------------- */

	var $form = $('#gff-ss-form');

	if ($form.length) {
		var $start = $('#gff-ss-start'),
			$end = $('#gff-ss-end');

		$start.attr('min', GFF_SS.now);
		$end.attr('min', GFF_SS.now);

		$form.on('change', 'input[name=mode]', function () {
			$form.find('.gff-ss-schedule').prop('hidden', this.value !== 'schedule');
			$('#gff-ss-publish').text(this.value === 'schedule' ? 'Schedule sale' : 'Publish sale');
		});

		$start.on('change', function () {
			$end.attr('min', this.value || GFF_SS.now);
		});

		function updateExample() {
			var type = $form.find('input[name=discount_type]:checked').val(),
				v = parseFloat($('#gff-ss-value').val());
			$('#gff-ss-unit').text(type === 'percent' ? '%' : GFF_SS.currency);
			if (!v || v <= 0) {
				$('#gff-ss-example').text('');
				return;
			}
			var after = type === 'percent' ? 100 * (1 - v / 100) : 100 - v;
			$('#gff-ss-example').text('Example: a ' + GFF_SS.currency + '100.00 item becomes ' + GFF_SS.currency + Math.max(0, after).toFixed(2) + '.');
		}
		$form.on('change input', 'input[name=discount_type], #gff-ss-value', updateExample);

		function formData() {
			return {
				title: $.trim($('#gff-ss-title').val()),
				mode: $form.find('input[name=mode]:checked').val(),
				start_at: $start.val(),
				end_at: $end.val(),
				discount_type: $form.find('input[name=discount_type]:checked').val(),
				discount_value: $('#gff-ss-value').val(),
				product_ids: $('#gff-ss-products').val() || [],
				category_ids: $('#gff-ss-cats').val() || []
			};
		}

		function validate(d) {
			var errors = [],
				now = GFF_SS.now;
			if (!d.title) errors.push('Please enter a title.');
			if (d.mode === 'schedule') {
				if (!d.start_at) errors.push('Please choose a start date.');
				else if (d.start_at <= now) errors.push('Start date must be in the future.');
				if (!d.end_at) errors.push('Please choose an end date.');
				else if (d.start_at && d.end_at <= d.start_at) errors.push('End date must be after the start date.');
			}
			if (!d.product_ids.length && !d.category_ids.length) errors.push('Select at least one product or category.');
			var v = parseFloat(d.discount_value);
			if (!v || v <= 0) errors.push('Discount must be greater than zero.');
			else if (d.discount_type === 'percent' && v >= 100) errors.push('Percentage discount must be less than 100.');
			return errors;
		}

		function showErrors(errors) {
			var $e = $form.find('.gff-ss-errors');
			if (!errors.length) {
				$e.prop('hidden', true).empty();
				return;
			}
			$e.html('<ul>' + $.map(errors, function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>').prop('hidden', false);
			$('html,body').animate({ scrollTop: $e.offset().top - 80 }, 200);
		}

		$('#gff-ss-preview-btn').on('click', function () {
			var d = formData(), $out = $('#gff-ss-preview-out'), $btn = $(this);
			if (!d.product_ids.length && !d.category_ids.length) {
				$out.html('<p class="gff-ss-muted">Select products or categories first.</p>');
				return;
			}
			$btn.prop('disabled', true);
			$out.html('<span class="spinner is-active"></span>');
			post('preview', { product_ids: d.product_ids, category_ids: d.category_ids })
				.done(function (res) {
					var html = '<p><strong>' + esc(res.data.count) + '</strong> product(s)/variation(s) will be discounted.</p>';
					if (res.data.sample.length) {
						html += '<ul>' + $.map(res.data.sample, function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ul>';
						if (res.data.count > res.data.sample.length) html += '<p class="gff-ss-muted">…and ' + (res.data.count - res.data.sample.length) + ' more.</p>';
					}
					$out.html(html);
				})
				.fail(function (xhr) { $out.html('<p class="gff-ss-error-text">' + esc(errMsg(xhr)) + '</p>'); })
				.always(function () { $btn.prop('disabled', false); });
		});

		$form.on('submit', function (e) {
			e.preventDefault();
			var d = formData(), errors = validate(d), $btn = $('#gff-ss-publish');
			showErrors(errors);
			if (errors.length) return;

			$btn.prop('disabled', true).addClass('is-busy');
			post('create', d)
				.done(function (res) {
					if (!res.success) {
						showErrors([res.data.message]);
						return;
					}
					if (res.data.mode === 'schedule') {
						window.location = res.data.url;
						return;
					}
					openProgress('Applying: ' + res.data.title, res.data.total);
					runQueue(res.data.rule_id, 'apply');
				})
				.fail(function (xhr) { showErrors([errMsg(xhr)]); })
				.always(function () { $btn.prop('disabled', false).removeClass('is-busy'); });
		});
	}

	/* ------------------------------------------------------------------
	 * Deactivate / resume
	 * ---------------------------------------------------------------- */

	var $confirm = $('#gff-ss-confirm'), pending = null;

	$(document).on('click', '.gff-ss-deactivate', function () {
		var $b = $(this);
		pending = { id: $b.data('id'), title: $b.data('title'), status: $b.data('status') };
		var items = pending.status === 'scheduled'
			? ['The scheduled start and end jobs will be cancelled.', 'No product prices have been changed yet, so nothing will be modified.', 'This cannot be undone — create a new rule to schedule it again.']
			: ['The sale price added by this rule will be removed from every product.', 'Any sale price that existed before this rule will be restored.', 'Products whose sale price was edited manually since will be left untouched.', 'This cannot be undone — the rule and its logs are kept for your records.'];
		$confirm.find('.gff-ss-confirm-title').text('“' + pending.title + '”');
		$confirm.find('.gff-ss-confirm-list').html($.map(items, function (t) { return '<li>' + esc(t) + '</li>'; }).join(''));
		$confirm.prop('hidden', false);
		$('body').addClass('gff-ss-lock');
	});

	$confirm.on('click', '.gff-ss-cancel', function () {
		$confirm.prop('hidden', true);
		$('body').removeClass('gff-ss-lock');
		pending = null;
	});

	$confirm.on('click', '.gff-ss-confirm-yes', function () {
		if (!pending) return;
		var p = pending, $btn = $(this);
		$btn.prop('disabled', true);
		post('deactivate', { rule_id: p.id })
			.done(function (res) {
				$confirm.prop('hidden', true);
				if (!res.success) {
					openProgress('Deactivate: ' + p.title, 0);
					addLogs([{ level: 'error', time: '', message: res.data.message }]);
					finish(p.id, 'revert', {});
					return;
				}
				openProgress('Deactivating: ' + p.title, res.data.total || 0);
				if (res.data.mode === 'cancelled') {
					addLogs(res.data.logs);
					finish(p.id, 'revert', res.data.report || {});
					$modal.find('.gff-ss-bar__fill').css('width', '100%');
					$modal.find('.gff-ss-bar__pct').text('Cancelled');
					$modal.find('.gff-ss-progress-count').text('');
					$modal.find('.gff-ss-hint').text('Schedule cancelled — no prices were changed.');
				} else {
					runQueue(p.id, 'revert');
				}
			})
			.fail(function (xhr) {
				$confirm.prop('hidden', true);
				window.alert(errMsg(xhr)); // eslint-disable-line no-alert
			})
			.always(function () { $btn.prop('disabled', false); pending = null; });
	});

	$(document).on('click', '.gff-ss-resume', function () {
		var $b = $(this);
		openProgress(($b.data('op') === 'revert' ? 'Deactivating: ' : 'Applying: ') + $b.data('title'), 0);
		runQueue($b.data('id'), $b.data('op'));
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && !$confirm.prop('hidden')) {
			$confirm.find('.gff-ss-cancel').trigger('click');
		}
	});
})(jQuery);
