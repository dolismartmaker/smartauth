-- Migration: introduce logical "user device" grouping.
--
-- Before: each smartauth_devices row was an independent device with its own
-- label. The only way to express "this is the same physical iPhone, just
-- another PWA on it" was to duplicate the label across rows, after which
-- _collapseDuplicateLabelDevices killed the siblings on each rename and
-- destroyed cross-app sessions silently.
--
-- After: each smartauth_devices row optionally points to a parent
-- smartauth_user_devices row through fk_user_device. Several technical
-- rows (one per PWA on the same physical device) share the same parent.
-- Revoking the parent cascades to every technical row in one shot.
ALTER TABLE llx_smartauth_devices ADD COLUMN fk_user_device INTEGER NULL;
ALTER TABLE llx_smartauth_devices ADD INDEX idx_smartauth_devices_fk_user_device (fk_user_device);
