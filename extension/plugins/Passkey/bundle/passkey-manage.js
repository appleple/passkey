(function () {
	'use strict';
	if (!window.PublicKeyCredential || typeof navigator.credentials === 'undefined') {
		var btn0 = document.getElementById('js-acms-passkey-register');
		if (btn0) {
			btn0.disabled = true;
			btn0.title = 'お使いのブラウザはパスキーに対応していません';
		}
		return;
	}

	// 「名前」入力欄が空なら User Agent から推測してデフォルト値を入れる
	(function prefillNickname() {
		var input = document.getElementById('js-acms-passkey-nickname');
		if (!input || input.value) return;
		var ua = navigator.userAgent || '';
		var suggested = '';
		if (/iPhone|iPad|iPod|Macintosh|Mac OS X/i.test(ua)) {
			suggested = 'My Apple devices';
		} else if (/Android/i.test(ua)) {
			suggested = 'Android';
		} else if (/Windows/i.test(ua)) {
			suggested = 'Windows Hello';
		} else if (/CrOS/i.test(ua)) {
			suggested = 'ChromeOS';
		} else if (/Linux/i.test(ua)) {
			suggested = 'Linux';
		}
		if (suggested) {
			input.value = suggested;
		}
	})();

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
	function setMsg(text, isError) {
		var el = document.getElementById('js-acms-passkey-message');
		if (!el) return;
		el.style.color = isError ? '#c00' : '#080';
		el.textContent = text || '';
	}

	var registerBtn = document.getElementById('js-acms-passkey-register');
	if (!registerBtn) return;

	registerBtn.addEventListener('click', async function () {
		setMsg('');
		var nicknameEl = document.getElementById('js-acms-passkey-nickname');
		var nickname = nicknameEl ? nicknameEl.value.trim() : '';
		if (!nickname) {
			setMsg('パスキーの名前を入力してください', true);
			if (nicknameEl) nicknameEl.focus();
			return;
		}
		registerBtn.disabled = true;
		try {
			var optJson = await postForm('ACMS_POST_Passkey_RegisterOptions');
			if (!optJson.ok) throw new Error(optJson.message || 'オプション取得に失敗');

			var pk = optJson.publicKey;
			pk.challenge = b64urlToBuf(pk.challenge);
			pk.user.id = b64urlToBuf(pk.user.id);
			if (pk.excludeCredentials && pk.excludeCredentials.length) {
				pk.excludeCredentials = pk.excludeCredentials.map(function (c) {
					return { id: b64urlToBuf(c.id), type: c.type, transports: c.transports };
				});
			}

			var cred = await navigator.credentials.create({ publicKey: pk });
			if (!cred) throw new Error('登録がキャンセルされました');

			var vJson = await postForm('ACMS_POST_Passkey_RegisterVerify', {
				clientDataJSON: bufToB64url(cred.response.clientDataJSON),
				attestationObject: bufToB64url(cred.response.attestationObject),
				nickname: nickname
			});
			if (!vJson.ok) throw new Error(vJson.message || '登録に失敗');

			var redirectUrl = registerBtn.getAttribute('data-redirect') || '';
			if (redirectUrl) {
				location.href = redirectUrl;
			} else {
				location.reload();
			}
		} catch (e) {
			var msg = e && e.message ? e.message : String(e);
			if (e && e.name === 'InvalidStateError') {
				msg = 'この認証器には既にこのサイトのパスキーが登録されています。'
					+ '（iCloud Keychain / Google Password Manager で別の端末から同期されている可能性があります）';
			} else if (e && e.name === 'NotAllowedError') {
				msg = 'パスキーの登録がキャンセルされました、またはタイムアウトしました。';
			} else if (e && e.name === 'SecurityError') {
				msg = 'セキュリティエラーです。HTTPS で接続しているか、サイトのドメインが正しいか確認してください。';
			}
			setMsg(msg, true);
			registerBtn.disabled = false;
		}
	});
})();
