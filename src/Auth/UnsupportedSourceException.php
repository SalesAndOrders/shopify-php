<?php

namespace Shopify\Auth;

/** The account's source (e.g. shopify_fbe) isn't handled by the token provider. */
class UnsupportedSourceException extends TokenUnavailableException
{
}
