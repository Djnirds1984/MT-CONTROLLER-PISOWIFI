/*
 * AIRCOINS NETFI — Core Portal Logic (SBC-only)
 *
 * Pure Piso WiFi: voucher login, vendo coin-slot, promo rates.
 * NO eload, NO charging features. NO dual-mode, NO CHAP.
 *
 * All portal context (login URL, MAC, IP, error) is parsed by the inline
 * script in login.html and exposed via window.PORTAL_PARAMS.
 *
 * Depends on: jQuery, Bootstrap 4, config.js
 */
(function ($) {
    "use strict";

    // ── State ───────────────────────────────────────────────────────────────
    var currentVendoIp    = "";
    var minutesPerPulse   = 15;
    var coinPollTimer     = null;
    var expectedCoins     = 0;
    var sessionPollTimer  = null;

    // ── Helper: get portal params (set by login.html init script) ───────────
    function getParams() {
        return window.PORTAL_PARAMS || {};
    }

    // ── Init ────────────────────────────────────────────────────────────────
    $(document).ready(function () {
        fetchVendoDevices();
        bindEvents();
        showLoginError();
    });

    // ── Show login error from URL param ─────────────────────────────────────
    function showLoginError() {
        var err = window.__loginError || "";
        if (err) {
            var msg = "Login failed: " + err;
            showToast(msg, "danger");
        }
    }

    // ── Fetch vendo devices from SBC API ────────────────────────────────────
    function fetchVendoDevices() {
        if (!isMultiVendo) {
            $("#vendoSelectDiv").hide();
            return;
        }

        var $sel = $("#vendoSelected");
        $sel.empty().append('<option value="" disabled selected>Loading...</option>');
        $("#vendoSelectDiv").show();

        var apiUrl = (sbcApiUrl || "") + "/api/vendo.php";

        $.ajax({
            url: apiUrl,
            method: "GET",
            dataType: "json",
            timeout: 8000
        }).done(function (resp) {
            var devices = resp && resp.devices ? resp.devices : [];
            if (devices.length === 0) {
                $sel.empty().append('<option value="" disabled selected>No vendo available</option>');
                return;
            }

            $sel.empty();
            $sel.append('<option value="" disabled selected>-- Select Coin Slot --</option>');
            multiVendoAddresses = [];

            for (var i = 0; i < devices.length; i++) {
                var d = devices[i];
                var label = d.name || ("Vendo " + (i + 1));
                $sel.append('<option value="' + i + '">' + label + '</option>');
                multiVendoAddresses.push({
                    ip: d.ip,
                    minutes_per_pulse: d.minutes_per_pulse || 15,
                    rates: d.rates || []
                });
            }

            // Auto-select if only one device
            if (devices.length === 1) {
                $sel.val(0).trigger("change");
            }
        }).fail(function () {
            $sel.empty().append('<option value="" disabled selected>No vendo available</option>');
        });
    }

    // ── Populate promo rates from selected device ───────────────────────────
    // Priority: 1) device /getRates  2) SBC API rates  3) minutesPerPulse calc
    function populatePromoRates(deviceIdx) {
        var $list = $("#promoRateList");
        $list.empty().append('<p style="text-align:center;color:#78909C;">Loading rates...</p>');

        if (!multiVendoAddresses || !multiVendoAddresses[deviceIdx]) {
            $list.empty().append('<p style="text-align:center;color:#78909C;">Select a coin slot to view rates.</p>');
            return;
        }

        var dev = multiVendoAddresses[deviceIdx];
        currentVendoIp  = dev.ip;
        minutesPerPulse  = dev.minutes_per_pulse || 15;

        // 1) Try fetching rates directly from the vendo device firmware
        $.ajax({
            url: "http://" + currentVendoIp + "/getRates",
            method: "GET",
            timeout: 4000
        }).done(function (rawText) {
            var rates = parseFirmwareRates(rawText);
            if (rates.length > 0) {
                renderRatesList($list, rates);
                return;
            }
            // Empty response — fall through to SBC / calc
            renderFromSbcOrCalc($list, dev);
        }).fail(function () {
            // Device unreachable for rates — try SBC API / calc
            renderFromSbcOrCalc($list, dev);
        });
    }

    // Parse firmware format: "1 Coin###15#|3 Coins###45#|..."
    // Rows by |, columns by #, columns[0]=name, columns[3]=minutes
    function parseFirmwareRates(raw) {
        if (!raw || typeof raw !== "string") return [];
        var rates = [];
        var rows = raw.split("|");
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i].trim();
            if (!row) continue;
            var cols = row.split("#");
            var name = (cols[0] || "").trim();
            var mins = parseInt(cols[3]) || 0;
            if (name && mins > 0) {
                // Extract coin count from name like "3 Coins" or "1 Coin"
                var coinMatch = name.match(/^(\d+)/);
                var coins = coinMatch ? parseInt(coinMatch[1]) : (i + 1);
                rates.push({ coins: coins, name: name, minutes: mins });
            }
        }
        return rates;
    }

    function renderRatesList($list, rates) {
        $list.empty();
        for (var i = 0; i < rates.length; i++) {
            var r = rates[i];
            $list.append(
                '<div class="promo-rate-item" data-coins="' + r.coins + '" data-minutes="' + r.minutes + '">' +
                '<span class="promo-coins">' + r.coins + '<small>coin' + (r.coins > 1 ? "s" : "") + '</small></span>' +
                '<span class="promo-time">' + r.minutes + '<small>min</small></span>' +
                '</div>'
            );
        }
    }

    function renderFromSbcOrCalc($list, dev) {
        // 2) SBC API rates
        if (dev.rates && dev.rates.length > 0) {
            $list.empty();
            for (var i = 0; i < dev.rates.length; i++) {
                var r = dev.rates[i];
                var unit = r.time_unit || "MIN";
                var val  = r.time_value || 0;
                $list.append(
                    '<div class="promo-rate-item" data-coins="' + r.coins + '" data-minutes="' + (unit === "HRS" ? val * 60 : val) + '">' +
                    '<span class="promo-coins">' + r.coins + '<small>coin' + (r.coins > 1 ? "s" : "") + '</small></span>' +
                    '<span class="promo-time">' + val + '<small>' + (unit === "HRS" ? "hr" : "min") + '</small></span>' +
                    '</div>'
                );
            }
        } else {
            // 3) Fallback: derive from minutesPerPulse
            $list.empty();
            var tiers = [1, 3, 5, 10];
            for (var j = 0; j < tiers.length; j++) {
                var coins = tiers[j];
                var mins  = coins * minutesPerPulse;
                $list.append(
                    '<div class="promo-rate-item" data-coins="' + coins + '" data-minutes="' + mins + '">' +
                    '<span class="promo-coins">' + coins + '<small>coin' + (coins > 1 ? "s" : "") + '</small></span>' +
                    '<span class="promo-time">' + mins + '<small>min</small></span>' +
                    '</div>'
                );
            }
        }
    }

    // ── Insert Coin flow ────────────────────────────────────────────────────
    function doInsertCoin() {
        if (!currentVendoIp) {
            showToast("Please select a coin slot first.", "warning");
            return;
        }

        var $btn = $("#insertBtn");
        $btn.prop("disabled", true).text("Inserting...");

        // Reset modal display
        $("#totalCoin").text("0");
        $("#totalTime").text("0 min");
        $("#expectedCoin").val("0");
        $("#voucherCodeText").text("");
        $("#codeGenerated").hide();
        $("#convertVoucherCode").hide();
        expectedCoins = 0;

        // Show the insert-coin modal
        $("#insertCoinModal").modal("show");

        // Poll the vendo device for coin insertion
        startCoinPoll();
    }

    function startCoinPoll() {
        if (coinPollTimer) clearInterval(coinPollTimer);

        coinPollTimer = setInterval(function () {
            $.ajax({
                url: "http://" + currentVendoIp + "/checkCoin",
                method: "POST",
                data: {},
                timeout: 5000
            }).done(function (resp) {
                if (resp && resp.status === "true") {
                    // Coin accepted
                    var total   = parseInt(resp.totalCoin) || 0;
                    var added   = parseInt(resp.newCoin) || 0;
                    var voucher = resp.voucher || "";
                    var secs    = parseInt(resp.timeAdded) || 0;
                    var mins    = Math.round(secs / 60);

                    expectedCoins = total;
                    $("#totalCoin").text(total);
                    $("#totalTime").text(mins + " min");
                    $("#expectedCoin").text(total);

                    if (voucher) {
                        $("#voucherCodeText").text(voucher);
                        $("#codeGenerated").show();
                        $("#convertVoucherCode").show();
                        stopCoinPoll();
                        showToast("Voucher generated: " + voucher, "success");
                    }
                }
                // status "false" with errorCode "coin.not.inserted" = no coin yet, keep polling
            }).fail(function () {
                // Device unreachable — keep polling silently
            });
        }, 1500);
    }

    function stopCoinPoll() {
        if (coinPollTimer) {
            clearInterval(coinPollTimer);
            coinPollTimer = null;
        }
        var $btn = $("#insertBtn");
        $btn.prop("disabled", false).text("INSERT COIN");
    }

    // ── Cancel top-up ───────────────────────────────────────────────────────
    function doCancelTopUp() {
        if (!currentVendoIp) return;

        $.ajax({
            url: "http://" + currentVendoIp + "/cancelTopUp",
            method: "POST",
            timeout: 3000
        }).always(function () {
            stopCoinPoll();
            $("#insertCoinModal").modal("hide");
        });
    }

    // ── Convert voucher (use generated code to login) ───────────────────────
    function doConvertVoucher() {
        var code = $("#voucherCodeText").text().trim();
        if (!code) return;

        $("#voucherInput").val(code);
        $("#insertCoinModal").modal("hide");
        stopCoinPoll();

        // Auto-submit
        doLogin(code);
    }

    // ── Generate voucher (no coin, just a code) ────────────────────────────
    function doGenerateVoucher() {
        if (!currentVendoIp) {
            showToast("Please select a coin slot first.", "warning");
            return;
        }

        $.ajax({
            url: "http://" + currentVendoIp + "/generateVoucher",
            method: "POST",
            timeout: 5000
        }).done(function (resp) {
            if (resp && resp.status === "true" && resp.voucher) {
                $("#voucherInput").val(resp.voucher);
                showToast("Voucher: " + resp.voucher, "success");
                doLogin(resp.voucher);
            } else {
                showToast("Failed to generate voucher.", "danger");
            }
        }).fail(function () {
            showToast("Vendo device unreachable.", "danger");
        });
    }

    // ── Login (HTTP-PAP — plaintext POST to router login URL) ───────────────
    // The hidden #sendin form's action and dst are already set by login.html's
    // init script from query params. We just fill credentials and submit.
    function doLogin(voucherCode) {
        var code = voucherCode || $("#voucherInput").val() || "";
        if (!code.trim()) {
            showToast("Please enter or generate a voucher code.", "warning");
            return;
        }

        var $btn = $("#connectBtn");
        $btn.prop("disabled", true).text("Connecting...");

        var loginUrl = getParams().login || "";
        if (!loginUrl) {
            showToast("No login URL available.", "danger");
            $btn.prop("disabled", false).text("CONNECT");
            return;
        }

        var $form = $("#sendin");
        $form.attr("action", loginUrl);
        $form.find("input[name=username]").val(code);
        $form.find("input[name=password]").val("");
        $form[0].submit();
    }

    // ── Member login (HTTP-PAP — username + password) ───────────────────────
    function doLoginMember() {
        var user = $("#memberUser").val() || "";
        var pass = $("#memberPass").val() || "";

        if (!user.trim()) {
            showToast("Please enter your username.", "warning");
            return;
        }

        var $btn = $("#memberLoginSubmit");
        $btn.prop("disabled", true).text("Connecting...");

        var loginUrl = getParams().login || "";
        if (!loginUrl) {
            showToast("No login URL available.", "danger");
            $btn.prop("disabled", false).text("LOGIN");
            return;
        }

        var $form = $("#memberForm");
        $form.attr("action", loginUrl);
        $form.find("input[name=username]").val(user);
        $form.find("input[name=password]").val(pass);
        $form[0].submit();
    }

    // ── Toast notification helper ───────────────────────────────────────────
    function showToast(msg, type) {
        type = type || "info";
        var bgClass = {
            "success": "bg-success",
            "danger":  "bg-danger",
            "warning": "bg-warning text-dark",
            "info":    "bg-info"
        }[type] || "bg-info";

        var $toast = $(
            '<div class="piso-toast ' + bgClass + '" style="position:fixed;top:20px;left:50%;transform:translateX(-50%);' +
            'z-index:9999;padding:12px 24px;border-radius:8px;color:#fff;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,.3);' +
            'opacity:0;transition:opacity .3s;">' + msg + '</div>'
        );
        $("body").append($toast);
        setTimeout(function () { $toast.css("opacity", 1); }, 50);
        setTimeout(function () {
            $toast.css("opacity", 0);
            setTimeout(function () { $toast.remove(); }, 300);
        }, 3000);
    }

    // ── Bind UI events ──────────────────────────────────────────────────────
    function bindEvents() {
        // Vendo dropdown change
        $("#vendoSelected").on("change", function () {
            var idx = parseInt($(this).val()) || 0;
            populatePromoRates(idx);
        });

        // Connect / submit voucher
        $("#connectBtn").on("click", function (e) {
            e.preventDefault();
            doLogin();
        });

        // Insert coin
        $("#insertBtn").on("click", function (e) {
            e.preventDefault();
            doInsertCoin();
        });

        // Cancel top-up
        $("#cancelTopUpBtn").on("click", function (e) {
            e.preventDefault();
            doCancelTopUp();
        });

        // Convert voucher
        $("#convertVoucherCode").on("click", function (e) {
            e.preventDefault();
            doConvertVoucher();
        });

        // Promo rate button
        $("#promoRateBtn").on("click", function (e) {
            e.preventDefault();
            $("#promoRateModal").modal("show");
        });

        // Member login button
        $("#memberLoginBtn").on("click", function (e) {
            e.preventDefault();
            $("#memberLoginModal").modal("show");
        });

        // Member login submit
        $("#memberLoginSubmit").on("click", function (e) {
            e.preventDefault();
            doLoginMember();
        });

        // Generate voucher
        $("#generateVoucherBtn").on("click", function (e) {
            e.preventDefault();
            doGenerateVoucher();
        });

        // Enter key on voucher input
        $("#voucherInput").on("keypress", function (e) {
            if (e.which === 13) {
                e.preventDefault();
                doLogin();
            }
        });
    }

})(jQuery);
