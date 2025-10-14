<?php
namespace FormKit\Core\Contracts;
interface MailerInterface { public function send(string $to, string $subject, string $body, array $headers=[]): bool; }
