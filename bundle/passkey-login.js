(function () {
	'use strict';
	var box = document.getElementById('js-acms-passkey-adminlogin');
	var btn = document.getElementById('js-acms-passkey-adminlogin-btn');
	var msgEl = document.getElementById('js-acms-passkey-adminlogin-message');

	if (!window.PublicKeyCredential || typeof navigator.credentials === 'undefined') {
		if (box) box.style.display = 'none';
		return;
	}
	if (!btn) return;

	function b64urlToBuf(s) {
		s = s.replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) s += '=';
		var bin = atob(s);
		var buf = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
		return buf.buffer;
	}
	function bufToB64url(buf) {
		var bytes = new Uint8Array(buf);
		var bin = '';
		for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
		return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
	function getCsrfToken() {
		if (typeof window.csrfToken === 'string' && window.csrfToken) return window.csrfToken;
		var meta = document.querySelector('meta[name="csrf-token"]');
		if (meta && meta.content) return meta.content;
		var input = document.querySelector('input[name="formToken"]');
		return input ? input.value : '';
	}
	async function postForm(action, extraFields) {
		var fd = new FormData();
		fd.append(action, '1');
		fd.append('formToken', getCsrfToken());
		if (extraFields) {
			Object.keys(extraFields).forEach(function (k) {
				fd.append(k, extraFields[k]);
			});
		}
		var res = await fetch(window.location.href, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		});
		var ct = res.headers.get('content-type') || '';
		if (ct.indexOf('application/json') < 0) {
			throw new Error('サーバが JSON を返しませんでした（HTTP ' + res.status + '）');
		}
		return await res.json();
	}
	function setMsg(t, isError) {
		if (!msgEl) return;
		msgEl.style.color = isError ? '#c00' : '#080';
		msgEl.textContent = t || '';
	}

	btn.addEventListener('click', async function () {
		setMsg('');
		btn.disabled = true;
		try {
			var o = await postForm('ACMS_POST_Passkey_LoginOptions');
			if (!o.ok) throw new Error(o.message || 'オプション取得に失敗');

			var pk = o.publicKey;
			pk.challenge = b64urlToBuf(pk.challenge);
			if (pk.allowCredentials && pk.allowCredentials.length) {
				pk.allowCredentials = pk.allowCredentials.map(function (c) {
					return { id: b64urlToBuf(c.id), type: c.type, transports: c.transports };
				});
			}
			var assertion = await navigator.credentials.get({ publicKey: pk });
			if (!assertion) throw new Error('認証がキャンセルされました');

			var v = await postForm('ACMS_POST_Passkey_LoginVerify', {
				credentialId: bufToB64url(assertion.rawId),
				clientDataJSON: bufToB64url(assertion.response.clientDataJSON),
				authenticatorData: bufToB64url(assertion.response.authenticatorData),
				signature: bufToB64url(assertion.response.signature),
				userHandle: assertion.response.userHandle ? bufToB64url(assertion.response.userHandle) : ''
			});
			if (!v.ok) throw new Error(v.message || 'ログインに失敗');

			location.href = v.redirect || '/';
		} catch (e) {
			var msg = e && e.message ? e.message : String(e);
			if (e && e.name === 'NotAllowedError') {
				msg = 'パスキー認証がキャンセルされました、またはタイムアウトしました。';
			} else if (e && e.name === 'SecurityError') {
				msg = 'セキュリティエラーです。HTTPS で接続しているか、サイトのドメインが正しいか確認してください。';
			}
			setMsg(msg, true);
			btn.disabled = false;
		}
	});
})();
