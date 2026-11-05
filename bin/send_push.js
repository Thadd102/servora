#!/usr/bin/env node
/**
 * Subnext High-Performance Native WebPush Dispatcher
 * RFC 8291 (aes128gcm payload encryption) + RFC 8292 (VAPID authentication)
 * Zero external dependencies. Uses Node.js standard crypto and fetch.
 */

const crypto = require('crypto');
const fs = require('fs');

function createVapidJwt(audience, subject, privateKeyBase64Url) {
    const header = { alg: 'ES256', typ: 'JWT' };
    const now = Math.floor(Date.now() / 1000);
    const payload = {
        aud: audience,
        exp: now + 86400,
        sub: subject
    };

    const encHeader = Buffer.from(JSON.stringify(header)).toString('base64url');
    const encPayload = Buffer.from(JSON.stringify(payload)).toString('base64url');
    const unsignedToken = `${encHeader}.${encPayload}`;

    const rawPriv = Buffer.from(privateKeyBase64Url, 'base64url');
    const pkcs8Header = Buffer.from('3041020100301306072a8648ce3d020106082a8648ce3d030107042730250201010420', 'hex');
    const pkcs8Der = Buffer.concat([pkcs8Header, rawPriv]);
    const privPem = `-----BEGIN PRIVATE KEY-----\n${pkcs8Der.toString('base64')}\n-----END PRIVATE KEY-----`;

    const sign = crypto.createSign('SHA256');
    sign.update(unsignedToken);
    const derSignature = sign.sign({ key: privPem, dsaEncoding: 'der' });

    let offset = 2;
    offset += 1;
    let rLen = derSignature[offset++];
    let r = derSignature.slice(offset, offset + rLen);
    offset += rLen;
    offset += 1;
    let sLen = derSignature[offset++];
    let s = derSignature.slice(offset, offset + sLen);

    if (r.length === 33 && r[0] === 0) r = r.slice(1);
    if (s.length === 33 && s[0] === 0) s = s.slice(1);
    if (r.length < 32) r = Buffer.concat([Buffer.alloc(32 - r.length), r]);
    if (s.length < 32) s = Buffer.concat([Buffer.alloc(32 - s.length), s]);

    const rawSignature = Buffer.concat([r, s]);
    return `${unsignedToken}.${rawSignature.toString('base64url')}`;
}

function encryptPayload(payloadText, clientP256dhBase64Url, clientAuthBase64Url) {
    const clientPublicKey = Buffer.from(clientP256dhBase64Url, 'base64url');
    const clientAuth = Buffer.from(clientAuthBase64Url, 'base64url');

    const localEcdh = crypto.createECDH('prime256v1');
    localEcdh.generateKeys();
    const localPublicKey = localEcdh.getPublicKey();

    const sharedSecret = localEcdh.computeSecret(clientPublicKey);
    const salt = crypto.randomBytes(16);

    const authInfo = Buffer.from('WebPush: info\0', 'utf8');
    const keyInfo = Buffer.concat([authInfo, clientPublicKey, localPublicKey]);
    const ikm = crypto.hkdfSync('sha256', sharedSecret, clientAuth, keyInfo, 32);

    const cekInfo = Buffer.from('Content-Encoding: aes128gcm\0', 'utf8');
    const nonceInfo = Buffer.from('Content-Encoding: nonce\0', 'utf8');

    const contentEncryptionKey = crypto.hkdfSync('sha256', ikm, salt, cekInfo, 16);
    const nonce = crypto.hkdfSync('sha256', ikm, salt, nonceInfo, 12);

    const padding = Buffer.from([0x02]);
    const plaintext = Buffer.concat([Buffer.from(payloadText, 'utf8'), padding]);

    const cipher = crypto.createCipheriv('aes-128-gcm', contentEncryptionKey, nonce);
    const ciphertext = Buffer.concat([cipher.update(plaintext), cipher.final()]);
    const tag = cipher.getAuthTag();

    const recordSize = Buffer.alloc(4);
    recordSize.writeUInt32BE(4096, 0);
    const idLen = Buffer.from([localPublicKey.length]);

    return Buffer.concat([
        salt,
        recordSize,
        idLen,
        localPublicKey,
        ciphertext,
        tag
    ]);
}

async function sendToSubscription(sub, payloadObj, vapid) {
    try {
        const urlObj = new URL(sub.endpoint);
        const audience = `${urlObj.protocol}//${urlObj.host}`;
        const jwt = createVapidJwt(audience, vapid.subject, vapid.privateKey);

        const payloadStr = JSON.stringify(payloadObj);
        const encryptedBody = encryptPayload(payloadStr, sub.p256dh, sub.auth);

        const headers = {
            'TTL': '86400',
            'Urgency': 'high',
            'Content-Encoding': 'aes128gcm',
            'Content-Type': 'application/octet-stream',
            'Authorization': `vapid t=${jwt}, k=${vapid.publicKey}`
        };

        const res = await fetch(sub.endpoint, {
            method: 'POST',
            headers: headers,
            body: encryptedBody
        });

        if (res.status === 201 || res.status === 200 || res.status === 204) {
            return { ok: true, status: res.status };
        }

        if (res.status === 404 || res.status === 410) {
            // Subscription expired or unregistered
            return { ok: false, status: res.status, expired: true };
        }

        const body = await res.text().catch(() => '');
        return { ok: false, status: res.status, error: body };
    } catch (err) {
        return { ok: false, status: 0, error: err.message };
    }
}

async function main() {
    let inputRaw = '';
    if (process.argv[2] && fs.existsSync(process.argv[2])) {
        inputRaw = fs.readFileSync(process.argv[2], 'utf8');
    } else {
        inputRaw = fs.readFileSync(0, 'utf8');
    }

    const data = JSON.parse(inputRaw);
    const subscriptions = data.subscriptions || [];
    const payload = data.payload || {};
    const vapid = data.vapid || {};

    let totalSent = 0;
    let totalFailed = 0;
    const expiredIds = [];
    const errors = [];

    // Deliver concurrently with throttle batches of 10
    const batchSize = 10;
    for (let i = 0; i < subscriptions.length; i += batchSize) {
        const batch = subscriptions.slice(i, i + batchSize);
        const promises = batch.map(async (sub) => {
            const res = await sendToSubscription(sub, payload, vapid);
            if (res.ok) {
                totalSent++;
            } else {
                totalFailed++;
                if (res.expired && sub.id) {
                    expiredIds.push(sub.id);
                }
                if (res.error) {
                    errors.push(`Sub #${sub.id}: ${res.error}`);
                }
            }
        });
        await Promise.all(promises);
    }

    const result = {
        ok: true,
        total_targeted: subscriptions.length,
        total_sent: totalSent,
        total_failed: totalFailed,
        expired_ids: expiredIds,
        errors: errors.slice(0, 5)
    };

    console.log(JSON.stringify(result));
}

main().catch((err) => {
    console.error(JSON.stringify({ ok: false, error: err.message }));
    process.exit(1);
});

