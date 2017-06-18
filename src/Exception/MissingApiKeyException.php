<?php

namespace HerokuClient\Exception;

/**
 * Named exception for when no Heroku API key is available for use.
 */
class MissingApiKeyException extends \LogicException
{
}
