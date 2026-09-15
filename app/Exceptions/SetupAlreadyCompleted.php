<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SetupAlreadyCompleted extends NotFoundHttpException {}
