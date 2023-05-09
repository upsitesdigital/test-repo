<?php

class Candidacy
{
    private $client;

    function __construct()
    {
        $this->client = new JobconvoApiClient();
    }

    public function Create($data)
    {

        $result = $this->client->Send('/pt-br/api/candidate/', $data);

        if ($result) {
            return $result;
        }

        return null;
    }

    public function Update($data)
    {
        // Ignoring for now, not implemented on JobConvo yet
        return;

        $result = $this->client->Update('/api/candidate?pk=' . $data->pk, $data);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function Get($params = null)
    {
        return $this->client->Get('/api/candidate' . ($params ? '?' . $params : ''));
    }

    public function GetByUser($user)
    {
        $result = $this->client->Get('/api/candidate?pk=' . $user->pk);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function GetByEmail($email)
    {
        $result = $this->client->Get('/api/candidate?candidate=' . $email);

        if ($result) {
            return $result[0];
        }

        return null;
    }

    public function Delete($pk)
    {
        $result = $this->client->Delete('pt-br/api/candidate/?pk=' . $pk);

        if ($result && isset($result['detail'])) {
            return true;
        }

        return false;
    }
}
