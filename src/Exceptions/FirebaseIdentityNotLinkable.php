<?php

namespace Isapp\FilamentFirebaseAuth\Exceptions;

use LogicException;

/**
 * A local record and a Firebase user cannot be linked.
 */
class FirebaseIdentityNotLinkable extends LogicException {}
