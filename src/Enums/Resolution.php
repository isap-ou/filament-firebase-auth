<?php

namespace Isapp\FilamentFirebaseAuth\Enums;

/**
 * How a Firebase user maps to a local record of a panel model.
 */
enum Resolution
{
    /**
     * A link exists for the UID and the model.
     */
    case Linked;

    /**
     * No link, but a record has the verified Firebase email. The link is not saved yet.
     */
    case LinkedByEmail;

    /**
     * No link and no record. The panel creates records on sign-in. The record is not saved yet.
     */
    case New;

    /**
     * The Firebase user cannot sign in to this panel.
     */
    case Blocked;
}
