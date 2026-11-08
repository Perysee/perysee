// Wording and small helpers for the security screens (two-factor authentication, devices, access log).

export const EVENT_LABELS = {
    "login.success": "Signed in",
    "login.failed": "Failed sign-in",
    "twofactor.enabled": "Two-factor authentication turned on",
    "twofactor.disabled": "Two-factor authentication turned off",
    "invitation.accepted": "Invitation accepted",
    "share.created": "Share link created",
    "session.revoked": "Session revoked",
    "storage.settings": "Storage budget settings changed",
    "storage.agreed": "Retention reduction agreed",
};

const METHOD_LABELS = { password: "a password", totp: "an authenticator code", recovery_code: "a recovery code" };

/** A sentence for one access log entry, from its event and context only. */
export function describeEntry(entry) {
    const base = EVENT_LABELS[entry.event] ?? entry.event;
    const context = entry.context ?? {};
    if (entry.event === "login.success" && context.method) {
        return `${base} with ${METHOD_LABELS[context.method] ?? context.method}`;
    }
    if (entry.event === "login.failed") {
        return context.stage === "two_factor" ? "Failed sign-in: wrong code" : "Failed sign-in: wrong password";
    }
    if (entry.event === "twofactor.disabled" && context.actor === "cli") {
        return `${base} from the command line`;
    }
    if (entry.event === "session.revoked") {
        return context.count !== undefined ? `Other sessions revoked (${context.count})` : `Session revoked${context.device ? ": " + context.device : ""}`;
    }
    if (entry.event === "storage.settings" && context.mode) {
        return `${base}: mode ${context.mode}`;
    }
    if (entry.event === "storage.agreed" && context.tier) {
        return `${base} (tier ${context.tier})`;
    }
    return base;
}

/** The text of the recovery codes download. */
export function recoveryCodesText(codes, name = "Perysee") {
    return `${name} recovery codes\nEach code works once.\n\n${codes.join("\n")}\n`;
}

/** "203.0.113.0" is shown as the network it stands for, since the last byte is not stored. */
export function networkLabel(ip) {
    if (!ip) return "Unknown network";
    return ip.includes(":") ? `${ip}/48` : `${ip.replace(/\.0$/, "")}.x`;
}

/** Marks the secret as groups of four so it can be read out. Already grouped keys are returned as they are. */
export function groupSecret(secret) {
    return secret.includes(" ") ? secret : secret.replace(/(.{4})/g, "$1 ").trim();
}
