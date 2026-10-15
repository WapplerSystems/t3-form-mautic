#
# Pending and confirmed double opt-in requests. The submitted contact fields stay
# here until the address is confirmed, so Mautic only ever holds the bare email of
# an unconfirmed sign-up. Confirmed rows remain as proof of consent.
#
CREATE TABLE tx_formmautic_doi (
	uid int(11) unsigned NOT NULL auto_increment,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	email varchar(255) DEFAULT '' NOT NULL,
	token_hash varchar(64) DEFAULT '' NOT NULL,
	contact_id int(11) unsigned DEFAULT '0' NOT NULL,
	payload text,
	segment_id int(11) unsigned DEFAULT '0' NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	language_id int(11) DEFAULT '0' NOT NULL,
	form_identifier varchar(255) DEFAULT '' NOT NULL,
	confirmed_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY token_hash (token_hash),
	KEY email (email)
);
